<?php

namespace PetarSpasic\LaravelHouse\Kanban\Console;

use PetarSpasic\LaravelHouse\Kanban\Policy\Questions;
use PetarSpasic\LaravelHouse\Kanban\Protocol\Applier;
use PetarSpasic\LaravelHouse\Kanban\Protocol\Context;
use PetarSpasic\LaravelHouse\Kanban\Protocol\Gates;
use PetarSpasic\LaravelHouse\Kanban\Protocol\Runtime;
use PetarSpasic\LaravelHouse\Kanban\Protocol\Staged;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\NotFound;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\PolicyRefused;
use PetarSpasic\LaravelHouse\Kanban\Support\Git;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'kanban:report')]
class ReportCommand extends Command
{
    protected $signature = 'kanban:report
        {id : Your card}
        {--status= : review|blocked}
        {--tick=* : Criteria this work satisfies (ids, comma lists allowed)}
        {--summary= : What was done}
        {--summary-file= : Read the summary from a file (- = stdin)}
        {--verified=* : "command → result" evidence}
        {--discovered=* : Out-of-scope work you found: "bug: Title — body"}
        {--upstream=* : A problem in the house package itself, in generic terms: "Title — body"}
        {--reason= : Why blocked (required for blocked, unless the question file has an Open question)}
        {--question-file= : A file in your worktree with ## Open question / ## Provisional decision sections for the owner}
        {--note= : Anything else for the main session}';

    protected $description = 'Worker: stage the report of your card (applied when you stop)';

    protected function perform(): int
    {
        $card = $this->store()->card($this->argument('id'));
        if (! in_array($card->stage(), ['doing', 'review'], true)) {
            throw new PolicyRefused("{$card->id()} is {$card->stage()}: only a card in doing or review takes a report");
        }
        $worktree = (new Context($this->paths(), $this->config()))->requireInside($card, $this->paths()->cwd, 'report');
        $summary = $this->option('summary-file') !== null ? $this->readFile((string) $this->option('summary-file')) : $this->option('summary');
        $questions = $this->option('question-file') !== null
            ? Questions::file($this->readFile($this->inside($worktree, (string) $this->option('question-file'))), (string) $this->option('status')) : [];
        $git = Git::untrusted($worktree);
        $report = Staged::report($card, (string) $this->option('status'), $this->option('tick'), $summary, $this->option('verified'),
            $this->option('discovered'), $this->option('reason') ?? Questions::block($questions), $this->option('note'), [
                'head' => (string) $git->line(['rev-parse', 'HEAD']),
                'worktree' => $this->paths()->relative($worktree),
                'session' => (getenv('KANBAN_SESSION') ?: null),
                // the last report applied: the same text reported again after it (a block cleared, a new round) is applied again
                'after' => array_reverse(array_column(array_filter($card->log(), fn (array $e) => ($e['event'] ?? null) === 'report'), 'id'))[0] ?? null,
            ], $this->upstream($card), $questions);
        $runtime = new Runtime($this->paths());
        $refusal = (new Applier($this->store(), $this->paths(), $this->config(), $runtime))->refusal($card, blocked: $report['status'] === 'blocked');
        if ($report['status'] === 'review' && $refusal === null) {
            $report['gates'] = $this->gates($card->id(), $git, $report['head'], $runtime);
        }
        $runtime->stage($report, 'report');

        $this->say("staged report for {$card->id()}: {$report['status']}, head ".substr($report['head'], 0, 7)
            .($report['ticks'] === [] ? '' : ', ticks '.implode(',', $report['ticks']))
            .($report['discovered'] === [] ? '' : ', '.count($report['discovered']).' discovered')
            .($report['upstream'] === [] ? '' : ', '.count($report['upstream']).' upstream')
            .($questions === [] ? '' : ', '.count($questions).' question'.(count($questions) === 1 ? '' : 's')));
        if (mb_strlen((string) $report['summary']) > Staged::SUMMARY) {
            $this->say('warning: the summary is '.mb_strlen((string) $report['summary']).' characters; the card keeps the first '.Staged::SUMMARY.'; shorten it and stage the report again');
        }
        if ($refusal !== null) {
            $this->say('warning: '.strtok($refusal, "\n").' — the stop is refused until that is fixed'
                .((new Gates($this->config()))->commands() === [] ? '' : '; then report again, which runs the gates'));
        }
        $this->say('applied when you stop');

        return self::SUCCESS;
    }

    /**
     * Runs main's gates in the worktree: the record of their pass, or refused with the failing gate (nothing staged).
     *
     * @return array<string, string>|null
     */
    private function gates(string $id, Git $git, string $head, Runtime $runtime): ?array
    {
        $gates = new Gates($this->config());
        if ($gates->commands() === []) {
            return null;
        }
        foreach ($gates->freshen($git->cwd) as $line) {
            $this->say($line);
        }
        $status = $git->attempt(['status', '--porcelain', '--untracked-files=all'])->out;
        $failure = $gates->failure($git->cwd);
        if ($git->line(['rev-parse', 'HEAD']) !== $head || $git->attempt(['status', '--porcelain', '--untracked-files=all'])->out !== $status) {
            $failure ??= 'a gate changed the worktree: gates must not write files (fix the gate in config/kanban.php or ask main)';
        }
        if ($failure !== null) {
            if ($runtime->staged($id, 'report') !== null) {
                $runtime->noteRefusal($id, 'report', $failure);
            }
            throw new PolicyRefused("report not staged: {$failure}");
        }
        $this->say('gates passed ('.count($gates->commands()).')');

        return $gates->passed($head);
    }

    /** A relative question file is the worktree's: the CLI may run from main's checkout with --in. */
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
