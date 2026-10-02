<?php

namespace PetarSpasic\LaravelHouse\Kanban\Policy;

use PetarSpasic\LaravelHouse\Kanban\Store\Card;
use PetarSpasic\LaravelHouse\Kanban\Store\Changes;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\Invalid;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\NotFound;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\PolicyRefused;
use PetarSpasic\LaravelHouse\Kanban\Store\Priority;
use PetarSpasic\LaravelHouse\Kanban\Store\Snapshot;

/**
 * `kanban fold FROM… --into=INTO` on a snapshot: INTO takes each FROM's body, criteria, labels, dependencies, the higher
 * priority and an open block; each FROM is dropped "folded into INTO"; open cards that depended on a FROM depend on INTO.
 */
final class Fold
{
    /**
     * @param  list<string>  $from  card ids
     * @return array{changes: Changes, added: int, repointed: list<string>, notes: list<string>}
     */
    public static function plan(Snapshot $snapshot, array $from, string $into): array
    {
        $from = array_values(array_unique($from));
        if ($from === []) {
            throw new Invalid('fold needs a card to fold');
        }
        if (in_array($into, $from, true)) {
            throw new Invalid("{$into} cannot fold into itself");
        }
        $target = self::card($snapshot, $into);
        $sources = array_map(fn (string $id) => self::card($snapshot, $id), $from);
        foreach ([$target, ...$sources] as $card) {
            if (! in_array($card->stage(), ['backlog', 'ready'], true)) {
                throw new PolicyRefused("{$card->id()} is in {$card->stage()}: fold takes backlog and ready cards");
            }
        }
        $locked = $snapshot->lockedStages();
        $changes = new Changes;
        $notes = [];

        $data = $target->data;
        $added = 0;
        foreach ($sources as $source) {
            $section = "## Folded from {$source->id()}: {$source->title()}";
            $body = trim((string) ($source->data['body'] ?? ''));
            $block = $source->blocked();
            if ($block !== null && ($data['blocked'] ?? null) === null) {
                $data['blocked'] = $block;
            } elseif ($block !== null && $block !== $data['blocked']) {
                $body = trim("{$body}\n\nBlocked: {$block}");
                $notes[] = "{$into} keeps its own block; {$source->id()}'s is in its folded section";
            }
            $current = rtrim((string) ($data['body'] ?? ''));
            $data['body'] = ($current === '' ? '' : "{$current}\n\n").$section.($body === '' ? '' : "\n\n{$body}")."\n";
            foreach ($source->acceptance() as $criterion) {
                $data = Edits::add($data, $criterion['text']);
                if ($criterion['done']) {
                    $data = Edits::tick($data, max(array_column($data['acceptance'], 'id')), true);
                }
                $added++;
            }
            $data['labels'] = array_values(array_unique([...$data['labels'] ?? [], ...$source->labels()]));
            $data['depends_on'] = [...$data['depends_on'] ?? [], ...$source->dependsOn()];
            if (Priority::rank($source->priority()) < Priority::rank((string) ($data['priority'] ?? 'normal'))) {
                $data['priority'] = $source->priority();
            }
        }
        $data['depends_on'] = array_values(array_diff(array_unique($data['depends_on']), [...$from, $into]));
        self::assertFits($into, $data);
        if ($target->stage() === 'ready' && str_starts_with((string) ($data['blocked'] ?? ''), Card::QUESTION)) {
            $data = Transitions::stage($data, 'backlog', 'move', 'an open question folded in');
            $notes[] = "{$into} goes back to backlog: it carries an open question";
        }
        $data['log'][] = ['event' => 'folded', 'from' => $from];
        Edits::assertOpen($target->data, $data, $locked);
        $changes->put($target, $data);

        foreach ($sources as $source) {
            $dropped = Transitions::stage($source->data, 'dropped', 'fold', "folded into {$into}");
            Edits::assertOpen($source->data, $dropped, $locked);
            $changes->put($source, $dropped);
        }

        $repointed = [];
        foreach ($snapshot->cards as $card) {
            $hit = array_intersect($card->dependsOn(), $from);
            if ($hit === [] || in_array($card->id(), [...$from, $into], true) || in_array($card->stage(), ['done', 'dropped'], true)) {
                continue;
            }
            if (in_array($card->stage(), $locked, true)) {
                throw new PolicyRefused("{$card->id()} is in {$card->stage()}, a locked stage, and depends on ".implode(', ', $hit).': fold once it is done');
            }
            $depends = array_map(fn (string $id) => in_array($id, $from, true) ? $into : $id, $card->dependsOn());
            $changes->put($card, ['depends_on' => array_values(array_unique($depends))] + $card->data);
            $repointed[] = $card->id();
        }

        return ['changes' => $changes, 'added' => $added, 'repointed' => $repointed, 'notes' => $notes];
    }

    /** @param  array<string, mixed>  $data */
    private static function assertFits(string $id, array $data): void
    {
        $over = [];
        foreach ([
            'acceptance criteria' => [count($data['acceptance'] ?? []), Card::MAX_CRITERIA],
            'labels' => [count($data['labels'] ?? []), Card::MAX_LABELS],
            'dependencies' => [count($data['depends_on'] ?? []), Card::MAX_DEPENDS],
            'body characters' => [mb_strlen((string) ($data['body'] ?? '')), Card::MAX_BODY],
        ] as $what => [$count, $max]) {
            if ($count > $max) {
                $over[] = "{$count} {$what} (at most {$max})";
            }
        }
        if ($over !== []) {
            throw new PolicyRefused("refused: {$id} would have ".implode(', ', $over).'; fold fewer cards or trim them first');
        }
    }

    private static function card(Snapshot $snapshot, string $id): Card
    {
        return $snapshot->card($id) ?? throw new NotFound("no card {$id}");
    }
}
