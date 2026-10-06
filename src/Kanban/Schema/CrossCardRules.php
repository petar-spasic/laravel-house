<?php

namespace PetarSpasic\LaravelHouse\Kanban\Schema;

use PetarSpasic\LaravelHouse\Kanban\Store\Card;
use PetarSpasic\LaravelHouse\Kanban\Store\Snapshot;
use PetarSpasic\LaravelHouse\Kanban\Store\Stage;

/** Rules that span files or fields: identity, placement, references, stage-coupled fields. */
final class CrossCardRules
{
    private const DONE_WORK_KEYS = ['branch', 'base', 'merge', 'started', 'finished'];

    /** @return list<string> "relative/path.json: message" */
    public function check(Snapshot $snapshot): array
    {
        $errors = [];
        foreach ($snapshot->problems as $path => $messages) {
            foreach ($messages as $message) {
                $errors[] = "{$path}: {$message}";
            }
        }
        foreach ($snapshot->cards as $card) {
            foreach ($this->card($card, $snapshot) as $message) {
                $errors[] = "{$card->path}: {$message}";
            }
        }
        foreach ($this->cycles($snapshot) as $cycle) {
            $errors[] = $snapshot->card($cycle[0])->path.': dependency cycle '.implode(' → ', $cycle);
        }

        return $errors;
    }

    /** @return list<string> */
    private function card(Card $card, Snapshot $snapshot): array
    {
        $errors = [];
        $board = $snapshot->boardOf($card);
        if ($board === null) {
            return ['card outside a board'];
        }
        // a card loaded without its epic is already among the snapshot's problems
        if ($card->epic() !== null && $snapshot->epic($card->epic()) === null && ! isset($snapshot->problems[$card->path])) {
            $errors[] = "unknown epic {$card->epic()} (`vendor/bin/kanban epic {$card->epic()}` creates it)";
        }
        foreach ($card->dependsOn() as $ref) {
            if ($ref === $card->id()) {
                $errors[] = 'refers to itself';
            } elseif ($snapshot->card($ref) === null) {
                $errors[] = "unknown card {$ref}";
            }
        }
        $ids = array_column($card->acceptance(), 'id');
        if (count($ids) !== count(array_unique($ids))) {
            $errors[] = 'acceptance ids repeat';
        }
        $logIds = array_column($card->log(), 'id');
        if (count($logIds) !== count(array_unique($logIds))) {
            $errors[] = 'log ids repeat';
        }

        return array_merge($errors, $this->work($card));
    }

    /** @return list<string> */
    private function work(Card $card): array
    {
        $errors = [];
        $stage = $card->stage();
        $work = $card->work();
        // a planning card holds a claim only while a planner works on it
        if ($stage !== 'planning' && Stage::isActive($stage) !== ($card->claim() !== null)) {
            $errors[] = Stage::isActive($stage) ? "claim is required in {$stage}" : "claim must be null in {$stage}";
        }
        if ($work === null && in_array($stage, ['review', 'done'], true)) {
            $errors[] = "work is required in {$stage}";
        }
        if ($work !== null && $stage === 'done' && array_diff(array_keys($work), self::DONE_WORK_KEYS) !== []) {
            $errors[] = 'work must be trimmed to '.implode(', ', self::DONE_WORK_KEYS).' in done';
        }
        $waiting = in_array($stage, ['backlog', 'ready', 'dropped'], true) || ($stage === 'planning' && ! $card->atWork());
        if ($work !== null && $waiting && array_keys($work) !== ['parked_branch']) {
            $errors[] = "work must be null (or only parked_branch) in {$stage}".($stage === 'planning' ? ' until a planner takes it' : '');
        }
        if (($stage === 'ready' || ($stage === 'planning' && ! $card->atWork())) && $card->asks()) {
            $errors[] = 'an open question (blocked="'.Card::QUESTION.'…") keeps it out of '.$stage.' until the owner answers';
        }

        return $errors;
    }

    /** @return list<list<string>> */
    private function cycles(Snapshot $snapshot): array
    {
        $state = [];
        $cycles = [];
        $visit = function (string $id, array $stack) use (&$visit, &$state, &$cycles, $snapshot): void {
            $state[$id] = 1;
            $stack[] = $id;
            foreach ($snapshot->card($id)?->dependsOn() ?? [] as $dep) {
                if (($state[$dep] ?? 0) === 1) {
                    $cycles[] = [...array_slice($stack, array_search($dep, $stack, true)), $dep];
                } elseif (! isset($state[$dep]) && $snapshot->card($dep) !== null) {
                    $visit($dep, $stack);
                }
            }
            $state[$id] = 2;
        };
        foreach (array_keys($snapshot->cards) as $id) {
            if (! isset($state[$id])) {
                $visit($id, []);
            }
        }

        return $cycles;
    }
}
