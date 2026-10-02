<?php

namespace PetarSpasic\LaravelHouse\Kanban\Console;

use PetarSpasic\LaravelHouse\Kanban\Policy\Edits;
use PetarSpasic\LaravelHouse\Kanban\Policy\Shape;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\Invalid;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\NotFound;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\PolicyRefused;
use PetarSpasic\LaravelHouse\Kanban\Store\Snapshot;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'kanban:set')]
class SetCommand extends Command
{
    protected $signature = 'kanban:set
        {id : Card id or unique prefix}
        {changes* : title= priority= type= labels=+a,-b depends_on=+ID accept+="…" accept[2]="…" accept-=3 tick=1 untick=2 blocked="…" body=@- note="…"}
        {--force : Change a card in a locked stage (main session only)}';

    protected $description = 'Change card fields (a card in a locked stage takes only note, blocked, tick and untick)';

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

        $locked = $snapshot->lockedStages();
        $updated = $store->update($card->id(), function (array $data) use ($changes, $locked, $force) {
            $before = $data;
            $removed = [];
            foreach ($changes as [$key, $index, $op, $value]) {
                $data = $this->apply($data, $key, $index, $op, $value, $removed);
            }
            Edits::assertOpen($before, $data, $locked, $force);

            return Edits::recordRemoved($before, $data, $removed);
        }, $this->actor(), $card->rev);

        if ($updated->updated() === $card->updated()) {
            $this->say("{$card->id()} unchanged");

            return self::SUCCESS;
        }
        $this->say("{$updated->id()} updated");
        $areas = array_values(array_diff($updated->areas(), $card->areas()));
        $depends = array_values(array_diff($updated->dependsOn(), $card->dependsOn()));
        if ($areas !== [] || $depends !== []) {
            foreach (Shape::hints($store->snapshot(), $updated, $areas, $depends) as $hint) {
                $this->say($hint);
            }
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
        $known = [...self::SCALARS, ...array_keys(self::SETS), 'accept', 'tick', 'untick', 'note'];
        if (! in_array($key, $known, true)) {
            throw new Invalid("unknown key '{$key}' (".implode(' ', $known).')');
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
    private function apply(array $data, string $key, ?int $index, string $op, string $value, array &$removed): array
    {
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
            'note' => Edits::note($data, $value),
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
        if ($op === '+=') {
            return Edits::add($data, $value, $removed);
        }
        $ids = array_column($data['acceptance'], 'id');
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
    private function tick(array $data, string $value, bool $done): array
    {
        $ids = array_column($data['acceptance'] ?? [], 'id');
        foreach (array_filter(array_map('trim', explode(',', $value))) as $item) {
            $data = Edits::tick($data, $this->criterion($ids, $item), $done);
        }

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
