<?php

namespace PetarSpasic\Kanban\Console;

use PetarSpasic\Kanban\Store\Exceptions\Invalid;
use PetarSpasic\Kanban\Store\Exceptions\NotFound;
use PetarSpasic\Kanban\Store\Exceptions\PolicyRefused;
use PetarSpasic\Kanban\Store\Snapshot;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'kanban:set')]
class SetCommand extends Command
{
    protected $signature = 'kanban:set
        {id : Card id or unique prefix}
        {changes* : title= priority= type= labels=+a,-b depends_on=+ID accept+="…" accept[2]="…" accept-=3 tick=1 untick=2 blocked="…" body=@- why=@- note="…" decided_on= supersedes=+ID resolution=}';

    protected $description = 'Change card fields';

    private const SCALARS = ['title', 'priority', 'type', 'blocked', 'body', 'why', 'decided_on', 'resolution'];

    private const SETS = ['labels' => 'labels', 'depends_on' => 'depends_on', 'supersedes' => 'supersedes'];

    protected function perform(): int
    {
        $this->requireMainOrOwner('set');
        $store = $this->store();
        $snapshot = $store->snapshot();
        $card = $snapshot->resolve($this->argument('id'));
        $fromStdin = array_filter($this->argument('changes'), fn (string $pair) => str_ends_with($pair, '=@-'));
        if (count($fromStdin) > 1) {
            throw new Invalid('only one value can be read from stdin (@-): set '.implode(', ', array_map(fn (string $pair) => strstr($pair, '=', true), $fromStdin)).' in separate runs');
        }
        $changes = array_map(fn (string $pair) => $this->parse($pair, $snapshot), $this->argument('changes'));

        $updated = $store->update($card->id(), function (array $data) use ($changes) {
            $before = $data;
            $removed = [];
            foreach ($changes as [$key, $index, $op, $value]) {
                $data = $this->apply($data, $key, $index, $op, $value, $removed);
            }
            if ($removed !== []) {
                $fields = array_keys(array_filter($data, fn ($v, $k) => $k !== 'log' && ($before[$k] ?? null) !== $v, ARRAY_FILTER_USE_BOTH));
                sort($fields);
                $data['log'][] = ['event' => 'set', 'fields' => $fields, 'acceptance_removed' => $removed];
            }

            return $data;
        }, $this->actor(), $card->rev);

        if ($updated->updated() === $card->updated()) {
            $this->say("{$card->id()} unchanged");

            return self::SUCCESS;
        }
        if ($updated->stage() === 'decided' && ($updated->data['supersedes'] ?? []) !== []) {
            $this->transitions()->supersede($updated->id(), $this->actor());
        }
        $this->say("{$updated->id()} updated");
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
        $known = [...self::SCALARS, ...array_keys(self::SETS), 'accept', 'tick', 'untick', 'note'];
        if (! in_array($key, $known, true)) {
            throw new Invalid("unknown key '{$key}' (".implode(' ', $known).')');
        }
        if ($value === '@-') {
            $value = (string) stream_get_contents(STDIN);
        }
        if (in_array($key, ['depends_on', 'supersedes'], true)) {
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
    private function apply(array $data, string $key, ?int $index, string $op, string $value, array &$removed): array
    {
        if (in_array($key, self::SCALARS, true)) {
            $this->expect($key, $op === '=' && $index === null);
            $data[$key] = $value === '' && in_array($key, ['blocked', 'decided_on', 'resolution'], true) ? null : $value;

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
            'note' => $this->note($data, $value),
            'tick', 'untick' => $this->tick($data, $value, $key === 'tick'),
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
        if (! array_key_exists('acceptance', $data)) {
            throw new Invalid('decisions have no acceptance criteria');
        }
        $items = $data['acceptance'];
        $ids = array_column($items, 'id');
        if ($op === '+=') {
            $previous = [];
            foreach ($data['log'] ?? [] as $entry) {
                array_push($previous, ...($entry['acceptance_removed'] ?? []));
            }
            $items[] = ['id' => max([0, ...$ids, ...$previous, ...$removed]) + 1, 'text' => $value, 'done' => false];
        } elseif ($op === '-=') {
            if (! in_array($data['stage'], ['backlog', 'ready'], true)) {
                throw new PolicyRefused('acceptance criteria are never deleted once work started');
            }
            $id = $this->criterion($ids, $value);
            $items = array_values(array_filter($items, fn (array $i) => $i['id'] !== $id));
            $removed[] = $id;
        } else {
            $this->expect('accept', $index !== null);
            $position = array_search($this->criterion($ids, (string) $index), $ids, true);
            $items[$position]['text'] = $value;
        }
        $data['acceptance'] = $items;

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function tick(array $data, string $value, bool $done): array
    {
        $ids = array_column($data['acceptance'] ?? [], 'id');
        foreach (array_filter(array_map('trim', explode(',', $value))) as $item) {
            $position = array_search($this->criterion($ids, $item), $ids, true);
            $data['acceptance'][$position]['done'] = $done;
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function note(array $data, string $text): array
    {
        if (trim($text) === '') {
            throw new Invalid('note needs text');
        }
        $data['log'][] = ['event' => 'note', 'text' => $text];

        return $data;
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
