<?php

namespace PetarSpasic\Kanban\Console;

use PetarSpasic\Kanban\Protocol\Applier;
use PetarSpasic\Kanban\Protocol\Context;
use PetarSpasic\Kanban\Protocol\Runtime;
use PetarSpasic\Kanban\Protocol\Staged;
use PetarSpasic\Kanban\Store\Exceptions\NotFound;
use PetarSpasic\Kanban\Store\Exceptions\PolicyRefused;
use PetarSpasic\Kanban\Support\Git;
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
        {--reason= : Why blocked (required for blocked)}
        {--note= : Anything else for the main session}';

    protected $description = 'Worker: stage the report of your card (applied when you stop)';

    protected function perform(): int
    {
        $card = $this->store()->card($this->argument('id'));
        if ($card->stage() !== 'doing') {
            throw new PolicyRefused("{$card->id()} is {$card->stage()}, not doing: only a card in doing takes a report");
        }
        $worktree = (new Context($this->paths(), $this->config()))->requireInside($card, $this->paths()->cwd, 'report');
        $summary = $this->option('summary-file') !== null ? $this->readFile((string) $this->option('summary-file')) : $this->option('summary');
        $git = new Git($worktree);
        $report = Staged::report($card, (string) $this->option('status'), $this->option('tick'), $summary, $this->option('verified'),
            $this->option('discovered'), $this->option('reason'), $this->option('note'), [
                'head' => (string) $git->line(['rev-parse', 'HEAD']),
                'worktree' => $this->paths()->relative($worktree),
                'session' => (getenv('KANBAN_SESSION') ?: null),
            ]);
        $runtime = new Runtime($this->paths());
        $runtime->stage($report, 'report');

        $this->say("staged report for {$card->id()}: {$report['status']}, head ".substr($report['head'], 0, 7)
            .($report['ticks'] === [] ? '' : ', ticks '.implode(',', $report['ticks']))
            .($report['discovered'] === [] ? '' : ', '.count($report['discovered']).' discovered'));
        if ($report['status'] === 'review' && ($refusal = (new Applier($this->store(), $this->paths(), $this->config(), $runtime))->refusal($card, false)) !== null) {
            $this->say('warning: '.strtok($refusal, "\n").' — the stop is refused until that is fixed');
        }
        $this->say('applied when you stop');

        return self::SUCCESS;
    }

    private function readFile(string $file): string
    {
        $content = $file === '-' ? stream_get_contents(STDIN) : @file_get_contents($file);

        return $content === false ? throw new NotFound("cannot read {$file}") : $content;
    }
}
