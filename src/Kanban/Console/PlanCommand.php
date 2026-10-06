<?php

namespace PetarSpasic\LaravelHouse\Kanban\Console;

use PetarSpasic\LaravelHouse\Kanban\Policy\Plan;
use PetarSpasic\LaravelHouse\Kanban\Policy\Questions;
use PetarSpasic\LaravelHouse\Kanban\Protocol\Context;
use PetarSpasic\LaravelHouse\Kanban\Protocol\Runtime;
use PetarSpasic\LaravelHouse\Kanban\Protocol\Staged;
use PetarSpasic\LaravelHouse\Kanban\Store\Card;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\Invalid;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\NotFound;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\PolicyRefused;
use PetarSpasic\LaravelHouse\Kanban\Support\Git;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * From the card's planning clone, its planner stages the plan (applied when it stops). From the main checkout, the owner or
 * the main session gives a card waiting in planning a plan of their own: checked against main, applied at once, the card
 * ready.
 */
#[AsCommand(name: 'kanban:plan')]
class PlanCommand extends Command
{
    protected $signature = 'kanban:plan
        {id : The card}
        {--plan-file= : The plan (- = stdin): ## Files, ## Steps and ## Criteria, as the planner\'s instructions say}
        {--status=ready : ready (the plan) or blocked (the card cannot be planned as it is)}
        {--reason= : Why it cannot be planned (blocked, unless the question file has an Open question)}
        {--question-file= : A file with ## Provisional decision / ## Open question sections for the owner}
        {--discovered=* : Out-of-scope work you found: "bug: Title — body"}
        {--upstream=* : A problem in the house package itself, in generic terms: "Title — body"}
        {--note= : Anything else for the main session}';

    protected $description = 'Planner: stage the plan of your card (applied when you stop). Owner or main: give a card in planning your own plan';

    protected function perform(): int
    {
        $card = $this->store()->card($this->argument('id'));
        $context = new Context($this->paths(), $this->config());
        $worktree = $card->atWork() ? $context->worktree($card) : null;
        $cwd = realpath($this->paths()->cwd) ?: $this->paths()->cwd;

        return $worktree !== null && ($cwd === $worktree || str_starts_with($cwd, $worktree.'/'))
            ? $this->stage($card, $context)
            : $this->give($card);
    }

    /** The planner's plan, checked against its clone's HEAD and staged. */
    private function stage(Card $card, Context $context): int
    {
        if ($card->stage() !== 'planning') {
            throw new PolicyRefused("{$card->id()} is {$card->stage()}: only a card in planning takes a plan");
        }
        $worktree = $context->requireInside($card, $this->paths()->cwd, 'plan');
        $git = Git::untrusted($worktree);
        $status = (string) $this->option('status');
        $questions = $this->option('question-file') !== null
            ? Questions::file($this->readFile($this->inside($worktree, (string) $this->option('question-file'))), $status) : [];
        $plan = null;
        if ($status === 'ready') {
            $file = $this->option('plan-file') ?? throw new Invalid('a plan needs --plan-file=.tmp/plan.md (the file you wrote it in)');
            $plan = Plan::check($this->readFile($this->inside($worktree, (string) $file)), array_column($card->acceptance(), 'id'),
                fn (string $path) => $git->attempt(['cat-file', '-e', "HEAD:{$path}"])->ok());
        }
        $staged = Staged::plan($card, $status, $plan, $this->option('reason') ?? Questions::block($questions), $this->option('discovered'), $this->option('note'), [
            'head' => (string) $git->line(['rev-parse', 'HEAD']),
            'base' => is_string($card->work()['base'] ?? null) ? $card->work()['base'] : null,
            'worktree' => $this->paths()->relative($worktree),
            'session' => (getenv('KANBAN_SESSION') ?: null),
        ], $this->upstream($card), $questions);
        (new Runtime($this->paths()))->stage($staged, 'plan');

        $files = $plan === null ? 0 : count(Plan::files($plan));
        $this->say("staged plan for {$card->id()}: ".($plan === null ? 'blocked' : count(explode("\n", $plan)).' lines, '.$files.' file'.($files === 1 ? '' : 's'))
            .($staged['discovered'] === [] ? '' : ', '.count($staged['discovered']).' discovered')
            .($staged['upstream'] === [] ? '' : ', '.count($staged['upstream']).' upstream')
            .($questions === [] ? '' : ', '.count($questions).' question'.(count($questions) === 1 ? '' : 's')));
        $hints = $plan === null ? [] : Plan::hints($plan);
        foreach ($hints as $hint) {
            $this->say("hint: {$hint}");
        }
        $this->say($hints === [] ? 'applied when you stop' : 'applied when you stop; to follow a hint, revise the plan and stage it again');

        return self::SUCCESS;
    }

    /** The owner's or the main session's own plan for a card no planner holds, checked against main and applied. */
    private function give(Card $card): int
    {
        $this->requireMainOrOwner('plan');
        foreach (['question-file' => null, 'reason' => null, 'note' => null, 'discovered' => [], 'upstream' => []] as $option => $none) {
            if ($this->option($option) !== $none) {
                throw new Invalid("--{$option} is for the card's planner; from the main checkout, plan gives a card a plan of your own");
            }
        }
        if ($this->option('status') !== 'ready') {
            throw new Invalid("a card that cannot be planned is blocked: `kanban set {$card->id()} blocked=\"…\"`");
        }
        $file = $this->option('plan-file') ?? throw new Invalid('give the plan with --plan-file=PATH (- reads stdin)');
        $git = new Git($this->paths()->main);
        $main = (string) $this->setting('main_branch', 'main');
        $base = $git->line(['rev-parse', '--verify', '-q', "refs/heads/{$main}"]) ?? throw new NotFound("no branch {$main} to check the plan against");
        $plan = Plan::check($this->readFile((string) $file), array_column($card->acceptance(), 'id'),
            fn (string $path) => $git->attempt(['cat-file', '-e', "{$base}:{$path}"])->ok());
        $planned = $this->transitions()->plan($card->id(), $plan, $base, $this->actor());
        $this->say("planned {$planned->id()}: ".count(explode("\n", $plan)).' lines, made on '.$main.' @'.substr($base, 0, 7)."; {$card->stage()}→{$planned->stage()}");
        foreach (Plan::hints($plan) as $hint) {
            $this->say("hint: {$hint}");
        }
        $this->reportPending();

        return self::SUCCESS;
    }

    /** A relative file is the worktree's: the CLI may run from main's checkout with --in. */
    private function inside(string $worktree, string $file): string
    {
        return $file === '-' || str_starts_with($file, '/') ? $file : $worktree.'/'.$file;
    }

    private function readFile(string $file): string
    {
        $content = $file === '-' ? stream_get_contents(STDIN) : @file_get_contents($file);

        return $content === false ? throw new NotFound("cannot read {$file}") : $content;
    }
}
