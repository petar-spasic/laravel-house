<?php

namespace PetarSpasic\LaravelHouse\Kanban\Console;

use PetarSpasic\LaravelHouse\Kanban\Code\Worktrees;
use PetarSpasic\LaravelHouse\Kanban\Policy\Plan;
use PetarSpasic\LaravelHouse\Kanban\Protocol\Context;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\NotFound;
use PetarSpasic\LaravelHouse\Kanban\Support\Clock;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'kanban:show')]
class ShowCommand extends Command
{
    protected $signature = 'kanban:show
        {id : Card id or unique prefix}
        {--json : JSON output}
        {--log=10 : Log entries to show (0 = none)}
        {--plan : Print only the plan}';

    protected $description = 'Show one card: header, body, checklist, plan, dependencies, claim, work, log';

    protected function perform(): int
    {
        $snapshot = $this->store()->snapshot();
        $card = $snapshot->resolve($this->argument('id'));
        if ($this->option('plan')) {
            $this->say(rtrim($card->plan() ?? throw new NotFound("{$card->id()} has no plan")));

            return self::SUCCESS;
        }
        if ($this->option('json')) {
            return $this->json($this->cardJson($card, $snapshot));
        }
        $data = $card->data;

        $this->say("{$card->id()} {$card->title()}");
        $this->say("board: {$card->board}");
        if ($card->epic() !== null) {
            $this->say("epic: {$card->epic()}".(($epic = $snapshot->epicOf($card)) !== null ? " ({$epic->title()})" : ''));
        }
        $this->say("type: {$card->type()}");
        $this->say("stage: {$card->stage()} since {$card->stageSince()} (".Clock::human(Clock::seconds($card->stageSince())).')');
        $this->say("priority: {$card->priority()}");
        if ($card->labels() !== []) {
            $this->say('labels: '.implode(', ', $card->labels()));
        }
        if ($card->blocked() !== null) {
            $this->say("blocked: {$card->blocked()}");
        }
        foreach ($card->dependsOn() as $id) {
            $dep = $snapshot->card($id);
            $this->say("depends on: {$id} ".($dep === null ? 'missing' : "{$dep->stage()} {$dep->title()}"));
        }
        if (($claim = $card->claim()) !== null) {
            $this->say("claim: {$claim['by']}".($claim['session'] ? " session {$claim['session']}" : '')." at {$claim['at']}");
        }
        foreach ($card->work() ?? [] as $key => $value) {
            if ($value !== null) {
                $this->say("work.{$key}: ".(is_array($value) ? json_encode($value, JSON_UNESCAPED_SLASHES) : $value));
            }
        }
        if (($agent = $this->agent($card->id(), $snapshot)) !== null) {
            $this->say("agent: {$agent}");
        }
        if ($card->atWork() && is_string($worktree = $card->work()['worktree'] ?? null)) {
            $agent = ['planning' => 'kanban-planner', 'doing' => 'kanban-worker', 'review' => 'kanban-evaluator'][$card->stage()];
            $this->say('spawn: '.Worktrees::spawnLine($card, $agent, $this->paths()->main.'/'.$worktree));
            $last = array_values(array_filter($card->log(), fn (array $e) => in_array($e['event'] ?? null, ['verdict', 'report', 'refresh'], true)));
            if ($card->stage() === 'doing' && ($last[count($last) - 1]['event'] ?? null) === 'verdict' && ($last[count($last) - 1]['decision'] ?? null) === 'reject') {
                $this->say("SendMessage (its worker, or a fresh one): Evaluator rejected {$card->id()}; run `vendor/bin/kanban context` for the failed checks, fix them, then report again.");
            }
        }
        foreach ($card->acceptance() as $item) {
            $this->say(($item['done'] ? '[x] ' : '[ ] ').$item['id'].' '.$item['text']);
        }
        if (trim((string) ($data['body'] ?? '')) !== '') {
            $this->say('body:');
            $this->say(rtrim($data['body']));
        }
        if (($plan = $card->plan()) !== null) {
            $planned = $card->planned() ?? [];
            $this->say('plan: '.count(explode("\n", rtrim($plan))).' lines'.(isset($planned['base']) ? ' @'.substr((string) $planned['base'], 0, 7) : '')
                .(isset($planned['at']) ? ' '.substr((string) $planned['at'], 0, 16) : '').(Plan::current($card) ? '' : ', no longer current')
                ." (`kanban show {$card->id()} --plan` prints it)");
        }
        $this->say("created: {$card->created()}");
        $this->say("updated: {$card->updated()}");
        if (($started = $card->entered('doing')) !== null) {
            $end = $card->stage() === 'done' ? $card->stageSince() : null;
            $this->say('cycle time: '.Clock::human(Clock::seconds($started, $end)).($end === null ? ' (running)' : ''));
        }
        if ($card->stage() === 'done') {
            $this->say('lead time: '.Clock::human(Clock::seconds($card->created(), $card->stageSince())));
        }
        $limit = (int) $this->option('log');
        $log = $limit > 0 ? array_slice($card->log(), -$limit) : [];
        if ($log !== []) {
            $this->say('log:');
        }
        foreach ($log as $entry) {
            $extra = array_diff_key($entry, array_flip(['id', 'at', 'by', 'who', 'event']));
            $text = match ($entry['event']) {
                'stage' => "stage {$entry['from']}→{$entry['to']}".(isset($entry['via']) ? " via {$entry['via']}" : '').(isset($entry['reason']) ? ": {$entry['reason']}" : ''),
                'set' => 'set '.implode(',', $entry['fields'] ?? []),
                'note' => 'note: '.($entry['text'] ?? ''),
                'conflict' => 'merge kept the other version of '.($entry['field'] ?? '?').'; replaced: '.mb_strimwidth((string) ($entry['lost'] ?? ''), 0, 200, '…'),
                default => $entry['event'].($extra === [] ? '' : ' '.json_encode($extra, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
            };
            $this->say("{$entry['at']} ".Context::actor($entry)." {$text}");
        }

        return self::SUCCESS;
    }
}
