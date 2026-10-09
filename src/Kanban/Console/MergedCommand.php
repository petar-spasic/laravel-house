<?php

namespace PetarSpasic\LaravelHouse\Kanban\Console;

use PetarSpasic\LaravelHouse\Kanban\Code\CloneFile;
use PetarSpasic\LaravelHouse\Kanban\Protocol\Applier;
use PetarSpasic\LaravelHouse\Kanban\Protocol\MergeState;
use PetarSpasic\LaravelHouse\Kanban\Protocol\Runtime;
use PetarSpasic\LaravelHouse\Kanban\Protocol\Staged;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\PolicyRefused;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'kanban:merged')]
class MergedCommand extends Command
{
    protected $signature = 'kanban:merged
        {id : The card being merged}
        {result : resolved (a conflict), fixed (a failed check), back (to its worker) or main (failing on main already)}
        {--note= : What you did or found, at most 2000 characters}
        {--note-file= : The note from a file, - for stdin}';

    protected $description = 'Merger: stage the result of the merge you were launched for (applied when you stop)';

    protected function perform(): int
    {
        $paths = $this->paths();
        if (! $paths->insideMergeClone()) {
            throw new PolicyRefused("merged runs in the merge clone ({$paths->mergeClone()}), where the merger works");
        }
        $card = $this->store()->card($this->argument('id'));
        $state = (new MergeState($paths))->read();
        if (($state['card'] ?? null) !== $card->id()) {
            throw new PolicyRefused($state === null ? 'no merge runs in the merge clone' : "the merge clone merges {$state['card']}, not {$card->id()}");
        }
        $file = $this->option('note-file');
        $note = $file === null ? $this->option('note') : CloneFile::given($paths->cwd, (string) $file);
        $runtime = new Runtime($paths);
        $outcome = (string) $this->argument('result');
        $head = (new Applier($this->store(), $paths, $this->config(), $runtime))->mergerHead($card, $state, $outcome);
        $item = Staged::merge($card, $outcome, $note, $state, ['head' => $head, 'session' => (getenv('KANBAN_SESSION') ?: null)]);
        $runtime->stage($item, 'merge');
        $this->say("staged merge result {$outcome} for {$card->id()}: applied when you stop");

        return self::SUCCESS;
    }
}
