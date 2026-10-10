<?php

namespace PetarSpasic\LaravelHouse\Kanban\Policy;

use PetarSpasic\LaravelHouse\Kanban\Store\Card;
use PetarSpasic\LaravelHouse\Kanban\Store\Snapshot;

/**
 * Checks that ran past their timeout in the merge queue: a `merge` entry `result: timeout`, which holds the queue while
 * its `base` is origin's main and its card is queued at its `head`. A move of main or an approval at another head ends
 * it; while the card is blocked it holds nothing. A timeout says nothing of main or of the card, so nothing runs it again: `kanban run` tells the main session.
 */
final class MergeTimeout
{
    /**
     * The open timeouts, one per base, card and command, in queue order.
     *
     * @return list<array{key: string, step: string, command: string, seconds: int, base: string, card: string, what: string}>
     */
    public static function open(Snapshot $snapshot, ?string $originMain): array
    {
        if ($originMain === null) {
            return [];
        }
        $rows = [];
        foreach (MergeQueue::cards($snapshot) as $card) {
            foreach ($card->log() as $entry) {
                if (($entry['event'] ?? null) !== 'merge' || ($entry['result'] ?? null) !== 'timeout' || ($entry['base'] ?? null) !== $originMain
                    || ($entry['head'] ?? null) !== self::approved($card)) {
                    continue;
                }
                $command = (string) ($entry['command'] ?? '');
                $key = "{$originMain}:{$card->id()}:{$command}";
                $rows[$key] = ['key' => $key, 'step' => (string) ($entry['step'] ?? ''), 'command' => $command, 'seconds' => (int) ($entry['seconds'] ?? 0),
                    'base' => $originMain, 'card' => $card->id(), 'what' => (string) ($entry['note'] ?? '')];
            }
        }

        return array_values($rows);
    }

    /**
     * Why the queue waits (MergeQueue::hold).
     *
     * @param  array{command: string, seconds: int, card: string}  $row
     */
    public static function hold(array $row): string
    {
        return "waits for a timed-out check (`{$row['command']}` ran past {$row['seconds']} s merging {$row['card']})";
    }

    /**
     * The line for the main session (`kanban run`'s attention, the brief).
     *
     * @param  array{step: string, command: string, seconds: int, base: string, card: string, what: string}  $row
     */
    public static function line(array $row): string
    {
        $card = $row['card'];
        $faster = $row['step'] === 'install' ? 'Make it faster on main'
            : 'Give it more time or make it faster: its `'.($row['step'] === 'gate' ? 'gates.report' : 'finish.check')."` entry `['run' => …, 'timeout' => …]` in config/kanban.php on main";

        return "a check timed out: `{$row['command']}` timed out after {$row['seconds']} s at ".substr($row['base'], 0, 7).", merging {$card}; the merge queue holds until main moves or {$card} is blocked. "
            ."{$faster}, then `vendor/bin/kanban publish`; or, if {$card}'s own tests hang, block it (`vendor/bin/kanban set {$card} blocked=\"…\"`)"
            .($row['what'] === '' ? '' : " (`vendor/bin/kanban show {$card} --log=5` has its output)");
    }

    private static function approved(Card $card): ?string
    {
        $head = $card->work()['approved']['head'] ?? null;

        return is_string($head) ? $head : null;
    }
}
