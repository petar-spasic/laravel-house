<?php

namespace PetarSpasic\LaravelHouse\Kanban\Policy;

use PetarSpasic\LaravelHouse\Kanban\Store\Card;
use PetarSpasic\LaravelHouse\Kanban\Store\Snapshot;

/** Backlog → Ready gate (R1–R7). */
final class ReadyPolicy
{
    /** @return list<string> refusals such as "R4 no acceptance criteria"; empty = ready */
    public function refusals(Card $card, Snapshot $snapshot, bool $requireBacklog = true): array
    {
        $refusals = [];
        if ($card->isDecision()) {
            $refusals[] = 'R1 a decision is not work';
        }
        $title = trim($card->title());
        if ($title === '' || mb_strlen($card->title()) > 120) {
            $refusals[] = 'R2 title must be 1-120 characters';
        }
        if (trim((string) ($card->data['body'] ?? '')) === '') {
            $refusals[] = 'R3 empty body';
        }
        $criteria = count($card->acceptance());
        if ($criteria === 0) {
            $refusals[] = 'R4 no acceptance criteria';
        } elseif ($criteria > 12) {
            $refusals[] = 'R4 more than 12 acceptance criteria';
        }
        foreach ($card->dependsOn() as $id) {
            $dep = $snapshot->card($id);
            if ($dep === null) {
                $refusals[] = "R5 dependency {$id} does not exist";
            } elseif ($dep->stage() === 'dropped') {
                $refusals[] = "R5 dependency {$id} is dropped";
            } elseif ($dep->isDecision() && $dep->stage() !== 'decided') {
                $refusals[] = "R5 decision {$id} is not decided";
            }
        }
        if ($this->inCycle($card, $snapshot)) {
            $refusals[] = 'R5 dependency cycle';
        }
        if ($card->blocked() !== null) {
            $refusals[] = 'R6 blocked: '.$card->blocked();
        }
        if ($requireBacklog && $card->stage() !== 'backlog') {
            $refusals[] = "R7 in {$card->stage()}, not backlog";
        }

        return $refusals;
    }

    private function inCycle(Card $card, Snapshot $snapshot): bool
    {
        $seen = [];
        $queue = $card->dependsOn();
        while ($queue !== []) {
            $id = array_shift($queue);
            if ($id === $card->id()) {
                return true;
            }
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            array_push($queue, ...($snapshot->card($id)?->dependsOn() ?? []));
        }

        return false;
    }
}
