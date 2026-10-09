<?php

namespace PetarSpasic\LaravelHouse\Kanban\Console;

use PetarSpasic\LaravelHouse\Kanban\Policy\Edits;
use PetarSpasic\LaravelHouse\Kanban\Policy\Shape;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\Invalid;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\NotFound;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\PolicyRefused;
use PetarSpasic\LaravelHouse\Kanban\Store\Snapshot;
use PetarSpasic\LaravelHouse\Kanban\Support\Git;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'kanban:set')]
class SetCommand extends Command
{
    protected $signature = 'kanban:set
        {id : Card id or unique prefix}
        {changes* : title= priority= type= epic=slug labels=+a,-b depends_on=+ID accept+="…" accept[2]="…" accept-=3 accept=@- tick=1 untick=2 blocked="…" body=@- note="…"}
        {--reason= : Why criteria are reworded (accept[N]=); lets a card in doing or review take it, unticked, and sends a review card back to doing}
        {--force : Change a card in a locked stage (main session only)}';

    protected $description = 'Change card fields (a card in a locked stage takes only note, blocked, tick, untick and a reworded criterion with --reason)';

    private const SCALARS = ['title', 'priority', 'type', 'blocked', 'body'];

    private const SETS = ['labels' => 'labels', 'depends_on' => 'depends_on'];

    protected function perform(): int
    {
        $this->requireMainOrOwner('set');
        $force = (bool) $this->option('force');
        if ($force && ! $this->actor()->canForce()) {
            throw new PolicyRefused('--force is for the main session only');
        }
        $store = $this->store();
        $snapshot = $store->snapshot();
        $card = $snapshot->resolve($this->argument('id'));
        $fromStdin = array_filter($this->argument('changes'), fn (string $pair) => str_ends_with($pair, '=@-'));
        if (count($fromStdin) > 1) {
            throw new Invalid('only one value can be read from stdin (@-): set '.implode(', ', array_map(fn (string $pair) => strstr($pair, '=', true), $fromStdin)).' in separate runs');
        }
        $changes = array_map(fn (string $pair) => $this->parse($pair, $snapshot), $this->argument('changes'));

        $reason = $this->option('reason');
        if ($reason !== null && trim($reason) === '') {
            throw new Invalid('--reason needs text');
        }
        $head = $this->head($card->work()['worktree'] ?? null);
        $locked = $snapshot->lockedStages();
        $updated = $store->update($card->id(), function (array $data) use ($changes, $locked, $force, $reason, $head) {
            $before = $data;
            $removed = [];
            foreach ($changes as [$key, $index, $op, $value]) {
                $data = $this->apply($data, $key, $index, $op, $value, $removed, $head);
            }
            Edits::assertOpen($before, $data, $locked, $force, $reason !== null);
            $data = Edits::replanned($before, Edits::recordRemoved($before, $data, $removed));

            return $reason === null ? $data : Edits::reworded($before, $data, trim($reason), $head);
        }, $this->actor(), $card->rev);

        if ($updated->updated() === $card->updated()) {
            $this->say("{$card->id()} unchanged");

            return self::SUCCESS;
        }
        $this->say("{$updated->id()} updated".($updated->stage() === $card->stage() ? '' : ", {$card->stage()}→{$updated->stage()}"
            .($updated->stage() === 'planning' ? ': its plan does not cover the change' : '')));
        $areas = array_values(array_diff($updated->areas(), $card->areas()));
        $depends = array_values(array_diff($updated->dependsOn(), $card->dependsOn()));
        $body = (string) ($updated->data['body'] ?? '');
        $criteria = array_values(array_diff(array_column($updated->acceptance(), 'text'), array_column($card->acceptance(), 'text')));
        foreach (Shape::hints($store->snapshot(), $updated, $areas, $depends, $body === ($card->data['body'] ?? '') ? null : $body, $criteria) as $hint) {
            $this->say($hint);
        }
        $this->reportPending();

        return self::SUCCESS;
    }

    /** @return array{0: string, 1: int|null, 2: string, 3: string} */
    private function parse(string $pair, Snapshot $snapshot): array
    {
        if (preg_match('/^([a-z_]+)(?:\[(\d+)\])?(\+=|-=|=)(.*)$/s', $pair, $m) !== 1) {
            throw new Invalid("cannot parse '{$pair}' (expected key=value)");
        }
        [, $key, $index, $op, $value] = $m;
        $known = [...self::SCALARS, ...array_keys(self::SETS), 'epic', 'accept', 'tick', 'untick', 'note'];
        if (! in_array($key, $known, true)) {
            throw new Invalid("unknown key '{$key}' (".implode(' ', $known).')');
        }
        if ($key === 'accept' && $op === '=' && $index === '' && $value !== '@-') {
            throw new Invalid('accept= replaces every criterion with the lines of stdin: accept=@- (accept+="…" adds one)');
        }
        if ($value === '@-') {
            $value = (string) stream_get_contents(STDIN);
        }
        if ($key === 'depends_on') {
            $value = implode(',', array_map(function (string $item) use ($snapshot) {
                $sign = in_array($item[0] ?? '', ['+', '-'], true) ? $item[0] : '';

                return $sign.$snapshot->resolve(ltrim($item, '+-'))->id();
            }, array_filter(array_map('trim', explode(',', $value)))));
        }

        return [$key, $index === '' ? null : (int) $index, $op, $value];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<int>  $removed
     * @return array<string, mixed>
     */
    private function apply(array $data, string $key, ?int $index, string $op, string $value, array &$removed, ?string $head): array
    {
        if ($key === 'epic') {
            $this->expect($key, $op === '=' && $index === null);
            unset($data['epic']);

            return trim($value) === '' ? $data : $data + ['epic' => trim($value)];
        }
        if (in_array($key, self::SCALARS, true)) {
            $this->expect($key, $op === '=' && $index === null);
            $data[$key] = $value === '' && $key === 'blocked' ? null : $value;

            return $data;
        }
        if (isset(self::SETS[$key])) {
            $this->expect($key, $op === '=' && $index === null);
            $items = array_filter(array_map('trim', explode(',', $value)));
            $signed = array_filter($items, fn (string $i) => in_array($i[0], ['+', '-'], true));
            $set = $signed === [] ? [] : ($data[$key] ?? []);
            foreach ($items as $item) {
                $name = ltrim($item, '+-');
                $set = $item[0] === '-' ? array_values(array_diff($set, [$name])) : [...$set, $name];
            }
            $data[$key] = array_values(array_unique($set));

            return $data;
        }

        return match ($key) {
            'note' => Edits::note($data, $value, $head),
            'tick', 'untick' => $this->tick($data, $value, $key === 'tick', $head),
            default => $this->accept($data, $index, $op, $value, $removed),
        };
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<int>  $removed
     * @return array<string, mixed>
     */
    private function accept(array $data, ?int $index, string $op, string $value, array &$removed): array
    {
        if ($op === '+=') {
            return Edits::add($data, $value, $removed);
        }
        $ids = array_column($data['acceptance'], 'id');
        if ($op === '=' && $index === null) {
            Edits::assertRemovable($data);
            $lines = array_values(array_filter(array_map('trim', explode("\n", $value)), fn (string $line) => $line !== ''));
            if ($lines === []) {
                throw new Invalid('accept=@- read no criteria from stdin (one per line)');
            }
            foreach ($ids as $id) {
                $data = Edits::remove($data, $id, $removed);
            }
            foreach ($lines as $line) {
                $data = Edits::add($data, $line, $removed);
            }

            return $data;
        }
        if ($op === '-=') {
            Edits::assertRemovable($data); // before the criterion is looked up: the stage refusal comes first

            return Edits::remove($data, $this->criterion($ids, $value), $removed);
        }
        $this->expect('accept', $index !== null);

        return Edits::edit($data, $this->criterion($ids, (string) $index), $value);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function tick(array $data, string $value, bool $done, ?string $head): array
    {
        $ids = array_column($data['acceptance'] ?? [], 'id');
        $ticked = [];
        foreach (array_filter(array_map('trim', explode(',', $value))) as $item) {
            $data = Edits::tick($data, $ticked[] = $this->criterion($ids, $item), $done);
        }
        $data['log'][] = array_filter(['event' => 'tick', 'ids' => $ticked, 'done' => $done, 'head' => $head], fn ($v) => $v !== null);

        return $data;
    }

    /** The HEAD of the card's worktree, which what main records about the card is checked against, or null. */
    private function head(mixed $worktree): ?string
    {
        if (! is_string($worktree) || $worktree === '') {
            return null;
        }
        $path = str_starts_with($worktree, '/') ? $worktree : $this->paths()->main.'/'.$worktree;

        return is_dir($path) ? Git::untrusted($path)->line(['rev-parse', '--verify', '-q', 'HEAD']) : null;
    }

    /** @param  list<int>  $ids */
    private function criterion(array $ids, string $value): int
    {
        if (! ctype_digit($value) || ! in_array((int) $value, $ids, true)) {
            throw new NotFound("no acceptance criterion {$value}");
        }

        return (int) $value;
    }

    private function expect(string $key, bool $ok): void
    {
        if (! $ok) {
            throw new Invalid("unsupported form for '{$key}'");
        }
    }
}
