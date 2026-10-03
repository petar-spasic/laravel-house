<?php

namespace PetarSpasic\LaravelHouse\Kanban\Console;

use Illuminate\Console\Command as IlluminateCommand;
use PetarSpasic\LaravelHouse\Kanban\Policy\Transitions;
use PetarSpasic\LaravelHouse\Kanban\Store\Actor;
use PetarSpasic\LaravelHouse\Kanban\Store\Card;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\KanbanException;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\PolicyRefused;
use PetarSpasic\LaravelHouse\Kanban\Store\Git\GitStore;
use PetarSpasic\LaravelHouse\Kanban\Store\Priority;
use PetarSpasic\LaravelHouse\Kanban\Store\Snapshot;
use PetarSpasic\LaravelHouse\Kanban\Store\Store;
use PetarSpasic\LaravelHouse\Kanban\Support\AgentStates;
use PetarSpasic\LaravelHouse\Kanban\Support\Paths;
use PetarSpasic\LaravelHouse\Kanban\Upstream\Findings;
use PetarSpasic\LaravelHouse\Kanban\Upstream\Scrubber;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Base for every kanban command: runs under artisan (`kanban:x`) and under `vendor/bin/kanban x`.
 * Output is plain, one fact per line; errors go to stderr, and a KanbanException sets the exit code.
 */
abstract class Command extends IlluminateCommand
{
    /** @var array<string, string>|null */
    private ?array $agentStates = null;

    final public function handle(): int
    {
        try {
            return $this->perform();
        } catch (KanbanException $e) {
            $this->fault($e->getMessage());
            foreach (array_slice($e->details, $e->getMessage() === ($e->details[0] ?? null) ? 1 : 0) as $detail) {
                $this->fault($detail);
            }

            return $e->exitCode();
        } catch (Throwable $e) {
            $this->fault('error: '.get_class($e).': '.$e->getMessage());
            if ($this->output->isVerbose()) {
                $this->fault($e->getTraceAsString());
            }

            return 1;
        }
    }

    abstract protected function perform(): int;

    protected function store(): Store
    {
        return $this->laravel->make(Store::class);
    }

    protected function paths(): Paths
    {
        return $this->laravel->make(Paths::class);
    }

    protected function transitions(): Transitions
    {
        return new Transitions($this->store());
    }

    protected function actor(): Actor
    {
        return Actor::fromEnvironment();
    }

    protected function setting(string $key, mixed $default = null): mixed
    {
        return $this->laravel->make('config')->get('kanban.'.$key, $default);
    }

    /** @return array<string, mixed> the whole `kanban` config */
    protected function config(): array
    {
        return (array) $this->laravel->make('config')->get('kanban', []);
    }

    /** One raw line on stdout (no markup interpretation). */
    protected function say(string $line = ''): void
    {
        $this->output->writeln($line, OutputInterface::OUTPUT_RAW);
    }

    /** One raw line on stderr. */
    protected function fault(string $line): void
    {
        $output = $this->output->getOutput();
        $target = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $target->writeln($line, OutputInterface::OUTPUT_RAW);
    }

    protected function json(mixed $data): int
    {
        $this->say(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    protected function requireMainOrOwner(string $what): void
    {
        if (! in_array($this->actor()->role, ['main', 'owner'], true)) {
            throw new PolicyRefused("{$what} is for the owner or the main session");
        }
        $this->requireMainCheckout($what);
    }

    /** Commands that change the board or its worktrees run from the main checkout, never from an agent's worktree. */
    protected function requireMainCheckout(string $what): void
    {
        $worktrees = $this->paths()->worktrees();
        if ($this->paths()->cwd === $worktrees || str_starts_with($this->paths()->cwd, $worktrees.'/')) {
            throw new PolicyRefused("{$what} runs from the main checkout ({$this->paths()->main}), not from a worktree");
        }
    }

    /**
     * The `--upstream` findings of a report or verdict, parsed and scrubbed.
     *
     * @return list<array{title: string, body: string}>
     */
    protected function upstream(Card $card): array
    {
        $texts = $this->option('upstream');

        return $texts === [] ? [] : Findings::stage($texts, Scrubber::forProject($this->paths(), (string) strstr($card->id(), '-', true), (string) $this->setting('remote', 'origin')));
    }

    /** Tells the user when the write only reached the journal (git unusable here). */
    protected function reportPending(): void
    {
        $pending = $this->store()->pending();
        if ($pending > 0) {
            $this->say("journaled: {$pending} write(s) not committed (git unusable here); the next host write, `kanban sweep` or `kanban sync` commits them");
        }
    }

    /**
     * With sync on (or auto next to a remote) the board is pushed once, so the others can join it. Never fatal: a remote that
     * cannot be reached now leaves the board local, and the sync status says so.
     */
    protected function publishOnce(): void
    {
        $store = $this->gitStore();
        if ($store === null || ! $store->syncOn() || ! $store->repo()->hasRemote()) {
            return;
        }
        try {
            SyncCommand::report($store->sync(), $this->say(...));
            $this->say('sync is on: the board is shared through '.$store->repo()->remote().'; KANBAN_SYNC=off keeps it on this machine');
        } catch (KanbanException $e) {
            $this->say('sync: '.$e->getMessage().' (the board stays on this machine until `vendor/bin/kanban sync` works)');
        }
    }

    protected function gitStore(): ?GitStore
    {
        $store = $this->store();

        return $store instanceof GitStore ? $store : null;
    }

    /** `KEY-7K2M9Q doing high feature work Title [epic billing] [agent 4m] [deps ok]` */
    protected function cardLine(Card $card, Snapshot $snapshot): string
    {
        $line = "{$card->id()} {$card->stage()} {$card->priority()} {$card->type()} {$card->board} {$card->title()}";
        if ($card->epic() !== null) {
            $line .= " [epic {$card->epic()}]";
        }
        if (($agent = $this->agent($card->id(), $snapshot)) !== null) {
            $line .= " [agent {$agent}]";
        }
        if ($card->dependsOn() !== []) {
            $open = array_filter($card->dependsOn(), fn (string $id) => ! $snapshot->isSatisfied($id));
            $line .= $open === [] ? ' [deps ok]' : ' [deps wait '.implode(',', $open).']';
        }
        if ($card->blocked() !== null) {
            $line .= ' [blocked: '.$card->blocked().']';
        }

        return $line;
    }

    /** Short status line: `KEY-7K2M9Q high work Title`. */
    protected function shortLine(Card $card): string
    {
        return "{$card->id()} ".Priority::short($card->priority())." {$card->board} {$card->title()}";
    }

    /** Agent bound to the card per the runtime (`4m`, `stopped`, `stale 25m`), or null. */
    protected function agent(string $cardId, Snapshot $snapshot): ?string
    {
        $this->agentStates ??= AgentStates::byCard($this->paths(), $snapshot->staleMinutes());

        return $this->agentStates[$cardId] ?? null;
    }

    /** @return array<string, mixed> */
    protected function cardJson(Card $card, Snapshot $snapshot): array
    {
        return $card->data + [
            'board' => (string) $card->board,
            'path' => $card->path,
            'stage_since' => $card->stageSince(),
            'deps_satisfied' => $snapshot->depsSatisfied($card),
            'agent' => $this->agent($card->id(), $snapshot),
        ];
    }
}
