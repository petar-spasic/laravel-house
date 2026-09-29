<?php

namespace PetarSpasic\Kanban\Console;

use Illuminate\Console\Command as IlluminateCommand;
use PetarSpasic\Kanban\Policy\Transitions;
use PetarSpasic\Kanban\Store\Actor;
use PetarSpasic\Kanban\Store\Card;
use PetarSpasic\Kanban\Store\Exceptions\KanbanException;
use PetarSpasic\Kanban\Store\Exceptions\PolicyRefused;
use PetarSpasic\Kanban\Store\Git\GitStore;
use PetarSpasic\Kanban\Store\Priority;
use PetarSpasic\Kanban\Store\Snapshot;
use PetarSpasic\Kanban\Store\Store;
use PetarSpasic\Kanban\Support\AgentStates;
use PetarSpasic\Kanban\Support\Paths;
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

    /** Tells the user when the write only reached the journal (git unusable here). */
    protected function reportPending(): void
    {
        $pending = $this->store()->pending();
        if ($pending > 0) {
            $this->say("journaled: {$pending} write(s) not committed (git unusable here); the next host write, `kanban sweep` or `kanban sync` commits them");
        }
    }

    protected function gitStore(): ?GitStore
    {
        $store = $this->store();

        return $store instanceof GitStore ? $store : null;
    }

    /** `KEY-7K2M9Q doing high feature platform/tooling Title [agent 4m] [deps ok]` */
    protected function cardLine(Card $card, Snapshot $snapshot): string
    {
        $line = "{$card->id()} {$card->stage()} {$card->priority()} {$card->type()} {$card->board} {$card->title()}";
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

    /** Short status line: `KEY-7K2M9Q high epic/board Title`. */
    protected function shortLine(Card $card): string
    {
        return "{$card->id()} ".Priority::short($card->priority())." {$card->board} {$card->title()}";
    }

    /** Agent bound to the card per the runtime (`4m`, `stopped`, `stale 25m`), or null. */
    protected function agent(string $cardId, Snapshot $snapshot): ?string
    {
        $this->agentStates ??= AgentStates::byCard($this->paths(), (int) $snapshot->setting('stale_after_minutes', 20));

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
