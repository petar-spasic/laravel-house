<?php

namespace PetarSpasic\Kanban\Schema;

use PetarSpasic\Kanban\Store\Card;
use PetarSpasic\Kanban\Store\CardType;
use PetarSpasic\Kanban\Store\Snapshot;
use PetarSpasic\Kanban\Store\Stage;

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
        if (CardType::kindOf($card->type()) !== $board->kind()) {
            $errors[] = "type {$card->type()} does not belong on a {$board->kind()} board";
        }
        foreach (array_merge($card->dependsOn(), $card->data['supersedes'] ?? []) as $ref) {
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

        return array_merge($errors, $card->isDecision() ? $this->decision($card, $snapshot) : $this->work($card));
    }

    /** @return list<string> */
    private function work(Card $card): array
    {
        $errors = [];
        $stage = $card->stage();
        $work = $card->work();
        if (Stage::isActive($stage) !== ($card->claim() !== null)) {
            $errors[] = Stage::isActive($stage) ? "claim is required in {$stage}" : "claim must be null in {$stage}";
        }
        if ($work === null && in_array($stage, ['review', 'done'], true)) {
            $errors[] = "work is required in {$stage}";
        }
        if ($work !== null && $stage === 'done' && array_diff(array_keys($work), self::DONE_WORK_KEYS) !== []) {
            $errors[] = 'work must be trimmed to '.implode(', ', self::DONE_WORK_KEYS).' in done';
        }
        if ($work !== null && in_array($stage, ['backlog', 'ready', 'dropped'], true) && array_keys($work) !== ['parked_branch']) {
            $errors[] = "work must be null (or only parked_branch) in {$stage}";
        }

        return $errors;
    }

    /** @return list<string> */
    private function decision(Card $card, Snapshot $snapshot): array
    {
        $errors = [];
        $stage = $card->stage();
        $data = $card->data;
        if (in_array($stage, ['decided', 'superseded'], true) && empty($data['decided_on'])) {
            $errors[] = "decided_on is required in {$stage}";
        }
        if ($stage === 'dropped' && empty($data['resolution'])) {
            $errors[] = 'resolution is required for a dropped decision';
        }
        $by = $data['superseded_by'] ?? null;
        if ($stage === 'superseded' && $by === null) {
            $errors[] = 'superseded_by is required in superseded';
        }
        if ($by !== null && ! in_array($card->id(), $snapshot->card($by)?->data['supersedes'] ?? [], true)) {
            $errors[] = "superseded_by {$by} does not list it in supersedes";
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
