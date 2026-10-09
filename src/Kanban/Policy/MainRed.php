<?php

namespace PetarSpasic\LaravelHouse\Kanban\Policy;

use PetarSpasic\LaravelHouse\Kanban\Store\Snapshot;

/**
 * What fails on main now: a `merge` entry `result: main` (the merge queue's base rerun, or the merger's judgement), which
 * holds the queue, and a `main_red` entry (an agent's `--discovered="main: …"`), which holds nothing. Each is open while
 * its `base` is origin's main, so any move of main closes it. Neither is ever a card: `kanban run` tells the main session.
 */
final class MainRed
{
    /**
     * The open failures, one per command and base, the queue's first.
     *
     * @return list<array{key: string, command: string, base: string, card: string, by: string, what: string, holds: bool}>
     */
    public static function open(Snapshot $snapshot, ?string $originMain): array
    {
        if ($originMain === null) {
            return [];
        }
        $rows = [[], []];
        foreach ($snapshot->cards() as $card) {
            foreach ($card->log() as $entry) {
                $holds = match (true) {
                    ($entry['base'] ?? null) !== $originMain => null,
                    ($entry['event'] ?? null) === 'merge' && ($entry['result'] ?? null) === 'main' => true,
                    ($entry['event'] ?? null) === 'main_red' => false,
                    default => null,
                };
                if ($holds === null) {
                    continue;
                }
                $command = (string) ($entry['command'] ?? '');
                $rows[(int) $holds]["{$originMain}:{$command}"] ??= ['key' => "{$originMain}:{$command}", 'command' => $command, 'base' => $originMain,
                    'card' => $card->id(), 'by' => (string) ($entry['by'] ?? ''), 'what' => (string) ($entry[$holds ? 'note' : 'body'] ?? ''), 'holds' => $holds];
            }
        }

        return array_values($rows[1] + $rows[0]);
    }

    /**
     * The line for the main session (`kanban run`'s attention, the brief).
     *
     * @param  array{command: string, base: string, card: string, by: string, what: string, holds: bool}  $row
     */
    public static function line(array $row): string
    {
        $at = substr($row['base'], 0, 7);
        if ($row['holds']) {
            return "main is red: `{$row['command']}` fails on main alone at {$at}, found merging {$row['card']}: fix it on main and `vendor/bin/kanban publish`; the merge queue holds until main moves"
                .($row['what'] === '' ? '' : " (`vendor/bin/kanban show {$row['card']} --log=5` has its output)");
        }
        $what = trim((string) strtok($row['what'], "\n"));

        return "main is red: `{$row['command']}` fails on main at {$at}, reported by the {$row['by']} of {$row['card']}"
            .($what === '' ? '' : ': '.mb_strimwidth($what, 0, 200, '…')).'; fix it on main and `vendor/bin/kanban publish`';
    }
}
