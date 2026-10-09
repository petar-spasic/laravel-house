<?php

namespace PetarSpasic\LaravelHouse\Kanban\Console;

use PetarSpasic\LaravelHouse\Kanban\Code\AgentRun;
use PetarSpasic\LaravelHouse\Kanban\Code\MergeBeat;
use PetarSpasic\LaravelHouse\Kanban\Code\MergeRun;
use PetarSpasic\LaravelHouse\Kanban\Code\MergeStep;
use PetarSpasic\LaravelHouse\Kanban\Protocol\Lease;
use PetarSpasic\LaravelHouse\Kanban\Protocol\MergeLease;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\Waiting;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'kanban:finish')]
class FinishCommand extends Command
{
    protected $signature = 'kanban:finish
        {id? : Card id or unique prefix; without one, the merge in flight here, else this machine\'s next card in the merge queue}
        {--abort : Give the merge lease back and reset the merge clone once no finish runs here; the card stays queued}
        {--follow : Only bring the main checkout to the remote\'s main and run its after-steps}
        {--no-rebuild : Leave main\'s stack alone when the merge changed its lockfiles, docker files or compose file}
        {--beat= : Internal: the beater of that merge lease}';

    protected $description = 'The merge queue\'s step for one approved card: merge lease, merge, checks in the merge stack, push of main, the card done and torn down';

    public const STEP_FAILED = MergeStep::STEP_FAILED;

    public const WAITING = Waiting::EXIT;

    public const MERGER = MergeStep::MERGER;

    public const RETURNED = MergeStep::RETURNED;

    protected function perform(): int
    {
        $this->requireMainOrOwner('finish');
        $paths = $this->paths();
        if (($lease = $this->option('beat')) !== null) {
            return (new MergeBeat($paths, new MergeLease($this->store(), $paths, $this->actor()), new AgentRun($paths, $this->config())))->loop((string) $lease);
        }
        (new Lease($paths))->acquire($this->actor());
        // one finish per checkout, for its whole life
        $lock = (new MergeRun($paths))->lock();
        $step = new MergeStep($paths, $this->config(), $this->store(), $this->actor(), $this->say(...), $this->fault(...), ! $this->option('no-rebuild'));
        $id = $this->argument('id');
        $exit = match (true) {
            (bool) $this->option('abort') => $step->abort($id),
            (bool) $this->option('follow') => $step->follow(),
            default => $step->run($id),
        };
        $this->reportPending();

        return $exit;
    }
}
