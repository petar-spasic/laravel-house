<?php

namespace PetarSpasic\LaravelHouse\Kanban\Console;

use PetarSpasic\LaravelHouse\Kanban\Hooks\SubagentStop;
use PetarSpasic\LaravelHouse\Kanban\Protocol\Context;
use PetarSpasic\LaravelHouse\Kanban\Protocol\Runtime;
use PetarSpasic\LaravelHouse\Kanban\Protocol\Staged;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\PolicyRefused;
use PetarSpasic\LaravelHouse\Kanban\Support\Git;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'kanban:verdict')]
class VerdictCommand extends Command
{
    protected $signature = 'kanban:verdict
        {id : The card you evaluated}
        {decision : approve|reject}
        {--check=* : One per criterion: N:pass|fail:"evidence"}
        {--issue=* : A problem outside the criteria (rejects)}
        {--discovered=* : A problem outside the card, filed as a backlog card: "bug: Title — body"}
        {--upstream=* : A problem in the house package itself, in generic terms: "Title — body"}
        {--note= : Anything else for the main session}';

    protected $description = 'Evaluator: stage the verdict of your card (applied when you stop)';

    protected function perform(): int
    {
        $card = $this->store()->card($this->argument('id'));
        $worktree = (new Context($this->paths(), $this->config()))->requireInside($card, $this->paths()->cwd, 'verdict');
        if ($card->stage() !== 'review') {
            throw new PolicyRefused("{$card->id()} is {$card->stage()}, not review: only a card in review takes a verdict");
        }
        $git = new Git($worktree);
        $main = 'refs/heads/'.$this->setting('main_branch', 'main');
        $runtime = new Runtime($this->paths());
        $evaluator = $runtime->agentFor($card->id(), SubagentStop::EVALUATOR);
        $since = $evaluator === null || $runtime->state($evaluator) !== 'live' ? null
            : (max((string) ($evaluator['started_at'] ?? ''), (string) ($evaluator['bound_at'] ?? '')) ?: null);
        $verdict = Staged::verdict($card, (string) $this->argument('decision'), $this->option('check'), $this->option('issue'), $this->option('discovered'), $this->option('note'), [
            'head' => (string) $git->line(['rev-parse', 'HEAD']),
            'base' => $git->line(['merge-base', 'HEAD', $main]),
            'worktree' => $this->paths()->relative($worktree),
            'session' => (getenv('KANBAN_SESSION') ?: null),
            'since' => $since,
        ], $this->upstream($card));
        $runtime->stage($verdict, 'verdict');

        $failed = array_keys(array_filter($verdict['checks'], fn (array $c) => $c['result'] === 'fail'));
        $this->say("staged verdict for {$card->id()}: {$verdict['decision']} at ".substr($verdict['head'], 0, 7)
            .($failed === [] ? '' : ', failing '.implode(',', $failed)).($verdict['issues'] === [] ? '' : ', '.count($verdict['issues']).' issue(s)')
            .($verdict['discovered'] === [] ? '' : ', '.count($verdict['discovered']).' discovered')
            .($verdict['upstream'] === [] ? '' : ', '.count($verdict['upstream']).' upstream'));
        $this->say('applied when you stop');

        return self::SUCCESS;
    }
}
