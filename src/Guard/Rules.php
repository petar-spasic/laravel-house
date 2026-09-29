<?php

namespace PetarSpasic\Kanban\Guard;

/**
 * The decision tables of the guard. Each check returns
 * [decision, reason] where decision is 'deny', 'allow' or null (normal permission flow).
 * Explicit allows are only ever given to kanban agents (worker, evaluator).
 */
final class Rules
{
    public const WORKER = 'kanban-worker';

    public const EVALUATOR = 'kanban-evaluator';

    private const BOARD_ONLY = 'docs/kanban is CLI-only: change the board with vendor/bin/kanban (new, set, move, report, verdict) and never edit its files or run git there.';

    private const DENIED_PROGRAMS = ['docker', 'docker-compose', 'gh', 'sudo', 'su'];

    private const READ_ONLY = [
        'ls', 'cat', 'head', 'tail', 'less', 'more', 'grep', 'egrep', 'fgrep', 'rg', 'ag', 'find', 'fd', 'wc', 'sort', 'uniq',
        'cut', 'tr', 'diff', 'cmp', 'comm', 'stat', 'file', 'pwd', 'echo', 'printf', 'which', 'whereis', 'type', 'command',
        'true', 'false', 'test', '[', '[[', 'date', 'basename', 'dirname', 'realpath', 'readlink', 'jq', 'tree', 'du', 'df',
        'column', 'nl', 'od', 'xxd', 'sha1sum', 'sha256sum', 'md5sum', 'printenv', 'id', 'whoami', 'hostname', 'uname',
        'sleep', 'seq', 'sed', 'cd', 'pushd', 'popd', 'dirs', ':', 'tac', 'rev', 'ps', '',
    ];

    private const TRANSPARENT = ['cd', 'pushd', 'popd', 'dirs', 'pwd', 'true', ':', 'echo', 'printf', 'cat', 'test', '[', 'sleep', ''];

    private const KANBAN_READ = ['list', 'show', 'status', 'context', 'next', 'help'];

    private const STACK_READ = ['status', 'wait', 'logs', 'url'];

    private const ID = '/^[A-Za-z][A-Za-z0-9]{1,9}-[0-9A-Za-z]{2,12}$/';

    private ?string $own;

    /** @var array<string, mixed>|null */
    private ?array $config = null;

    /** @var list<string>|null */
    private ?array $boostSkills = null;

    /** @var array<string, array<string, mixed>>|null */
    private ?array $cards = null;

    /**
     * @param  'main'|'worker'|'evaluator'|'other'  $actor
     * @param  array<string, mixed>|null  $binding  runtime agents/<agent_id>.json
     */
    public function __construct(
        private Locations $locations,
        private string $actor,
        private ?string $agentId,
        private ?string $agentType,
        private ?array $binding,
        private string $cwd,
        private string $home,
    ) {
        $worktree = $binding['worktree'] ?? null;
        $this->own = is_string($worktree) && $worktree !== '' ? $locations->canonical($this->absolute($worktree, $locations->main)) : null;
    }

    public function agentsDir(): string
    {
        return $this->locations->main.'/.git/laravel-kanban/agents';
    }

    /**
     * @return array{0: ?string, 1: string}
     */
    public function edit(string $path): array
    {
        if ($path === '') {
            return [null, ''];
        }

        $where = $this->where($this->absolute($path, $this->cwd));

        if ($where === Locations::BOARD) {
            return ['deny', self::BOARD_ONLY];
        }

        if ($this->actor === 'main') {
            return [null, ''];
        }

        if (($skill = $this->managedSkill($this->absolute($path, $this->cwd))) !== null) {
            return ['deny', $this->managedSkillReason($skill)];
        }

        return match ($where) {
            Locations::GITDIR => ['deny', '.git belongs to the main session: change code in your worktree and commit it with plain git add/commit there.'],
            Locations::MAIN => match ($this->actor) {
                'worker' => ['deny', $this->enterFirst()],
                'evaluator' => ['deny', $this->evaluatorReadOnly()],
                default => $this->strictAllows($path) ? [null, ''] : ['deny', 'guard.strict is on: subagents may write in the main checkout only under guard.main_write_paths ('.implode(', ', $this->config()['guard']['main_write_paths'] ?? []).'); write in a worktree or ask the main session.'],
            },
            Locations::OWN => match ($this->actor) {
                'worker' => Locations::within($this->locations->canonical($this->absolute($path, $this->cwd)), (string) $this->own.'/vendor')
                    ? ['deny', 'vendor/ is copied from main and dependencies are the owner\'s: never edit it; report the card blocked if a dependency must change.']
                    : ['allow', 'kanban: write inside the bound worktree'],
                'evaluator' => ['deny', $this->evaluatorReadOnly()],
                default => [null, ''],
            },
            Locations::OTHER => ['deny', 'That file belongs to another worktree: edit only inside '.($this->own ?? 'your own worktree').'.'],
            default => [null, ''],
        };
    }

    /**
     * @return array{0: ?string, 1: string}
     */
    public function bash(string $command): array
    {
        $parsed = ShellParser::parse($command, $this->cwd, $this->home);
        $subagent = $this->actor !== 'main';

        if ($parsed['error'] !== null && $subagent) {
            return ['deny', $this->unverifiable($parsed['error'])];
        }

        $allowed = false;
        $neutral = false;

        foreach ($parsed['cmds'] as $cmd) {
            [$decision, $reason] = $this->command($cmd);

            if ($decision === 'deny') {
                return ['deny', $reason];
            }
            $allowed = $allowed || $decision === 'allow';
            $neutral = $neutral || $decision === null;
        }

        return $allowed && ! $neutral && $parsed['error'] === null ? ['allow', 'kanban: every command is allowed for this agent'] : [null, ''];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{0: ?string, 1: string}
     */
    public function enterWorktree(array $input): array
    {
        if (! $this->isKanban()) {
            return [null, ''];
        }

        $stage = $this->actor === 'worker' ? 'doing' : 'review';
        $path = $input['path'] ?? $input['worktree_path'] ?? null;
        $candidates = $this->candidates($stage);

        if (! is_string($path) || $path === '') {
            return ['deny', "Call EnterWorktree(path: …) with your card's worktree. Cards in {$stage}: {$candidates}."];
        }

        $target = $this->locations->canonical($this->absolute($path, $this->cwd));
        $card = null;

        foreach ($this->cards() as $candidate) {
            $worktree = $candidate['work']['worktree'] ?? null;
            if (is_string($worktree) && $worktree !== '' && $this->locations->canonical($this->absolute($worktree, $this->locations->main)) === $target) {
                $card = $candidate;
                break;
            }
        }

        if ($card === null || ($card['stage'] ?? null) !== $stage) {
            return ['deny', "{$path} is not the worktree of a card in {$stage}. Cards in {$stage}: {$candidates}."];
        }

        if ($this->own !== null && $this->own !== $target) {
            return ['deny', "You are already bound to {$this->own} ({$this->binding['card']}); one agent works on exactly one card."];
        }

        foreach ($this->liveAgents() as $agent) {
            if ($agent['agent_id'] !== $this->agentId && ($agent['card'] === $card['id'] || $agent['target'] === $target)) {
                return ['deny', "{$card['id']} is already bound to live agent {$agent['agent_id']} ({$agent['agent_type']}); stop and tell the main session."];
            }
        }

        $this->bind($card);

        return ['allow', "kanban: bound to {$card['id']}"];
    }

    /**
     * @return array{0: ?string, 1: string}
     */
    public function exitWorktree(): array
    {
        return $this->isKanban()
            ? ['deny', 'Stay in your card\'s worktree. When done, run vendor/bin/kanban '.($this->actor === 'worker' ? 'report' : 'verdict').' <ID> … and end with your one-line final message.']
            : [null, ''];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{0: ?string, 1: string}
     */
    public function agent(array $input): array
    {
        $type = $input['subagent_type'] ?? null;

        if ($type !== self::WORKER && $type !== self::EVALUATOR) {
            return [null, ''];
        }

        $stage = $type === self::WORKER ? 'doing' : 'review';
        $key = $this->config()['key'] ?? null;
        $pattern = is_string($key) && $key !== ''
            ? '/\b'.preg_quote($key, '/').'-[0-9A-Z]{4,12}\b/i'
            : '/\b[A-Z][A-Z0-9]{1,9}-[0-9A-Z]{4,12}\b/';
        preg_match_all($pattern, (string) ($input['prompt'] ?? ''), $m);

        $cards = $this->cards();
        $named = array_values(array_unique(array_filter(
            array_map('strtoupper', $m[0]),
            fn (string $id): bool => ($cards[$id]['stage'] ?? null) === $stage,
        )));

        if (count($named) !== 1) {
            return ['deny', "The prompt must name exactly one card in {$stage} (found ".(count($named) ?: 'none')."). Cards in {$stage}: {$this->candidates($stage)}. Use the spawn line printed by vendor/bin/kanban start|refresh."];
        }

        $card = $cards[$named[0]];

        if (empty($card['work']['worktree'])) {
            return ['deny', "{$card['id']} has no worktree: run vendor/bin/kanban start {$card['id']} first."];
        }

        foreach ($this->liveAgents() as $agent) {
            if ($agent['card'] === $card['id'] && $agent['agent_type'] === $type) {
                return ['deny', "{$card['id']} already has a live {$type} ({$agent['agent_id']}): SendMessage it instead of spawning another."];
            }
        }

        $this->recordSpawn($card, $type);

        return [null, ''];
    }

    /**
     * @param  array<string, mixed>  $cmd
     * @return array{0: ?string, 1: string}
     */
    private function command(array $cmd): array
    {
        $subagent = $this->actor !== 'main';

        if (isset($cmd['unverifiable'])) {
            return $subagent ? ['deny', $this->unverifiable($cmd['unverifiable'])] : [null, ''];
        }

        $base = $cmd['dir'] ?? ($subagent ? $this->locations->main : null);
        $dirWhere = $base === null ? Locations::MAIN : $this->where($base);
        $program = $cmd['program'];
        $outsideWrite = false;

        foreach ($this->writeTargets($cmd) as $word) {
            $target = ShellParser::path($word, $base, $this->home);

            if ($target === null) {
                if ($subagent) {
                    return ['deny', $this->unverifiable("the write target {$word['v']} cannot be resolved")];
                }

                continue;
            }

            $where = $this->where($target);

            if ($where === Locations::BOARD) {
                return ['deny', self::BOARD_ONLY];
            }
            if ($where === Locations::GITDIR && $subagent) {
                return ['deny', 'Writing into .git is the main session\'s: use plain git add/commit inside your worktree.'];
            }
            if ($subagent && ($skill = $this->managedSkill($target)) !== null) {
                return ['deny', $this->managedSkillReason($skill)];
            }
            $outsideWrite = $outsideWrite || ! in_array($where, [Locations::OUTSIDE, Locations::OWN], true) && ! str_starts_with($target, '/dev/');
        }

        if (! $subagent) {
            if ($program === 'git') {
                $git = GitCall::parse($cmd, $cmd['dir'], $this->home);
                if ($git->class !== GitCall::READ && $git->dir !== null && $this->where($git->dir) === Locations::BOARD) {
                    return ['deny', self::BOARD_ONLY];
                }
            }

            return [null, ''];
        }

        foreach ($cmd['assigns'] as $assign) {
            if (str_starts_with($assign['name'], 'GIT_') && ! in_array($assign['name'], GitCall::HARMLESS_ENV, true)) {
                return ['deny', $this->gitRedirect($assign['name'].'=')];
            }
        }

        if (in_array($program, ['export', 'declare', 'typeset', 'readonly'], true)) {
            foreach (array_slice($cmd['words'], 1) as $word) {
                if (str_starts_with($word['v'], 'GIT_') && ! in_array(explode('=', $word['v'])[0], GitCall::HARMLESS_ENV, true)) {
                    return ['deny', $this->gitRedirect(explode('=', $word['v'])[0].'=')];
                }
            }
        }

        if ($cmd['sudo'] || in_array($program, self::DENIED_PROGRAMS, true)) {
            $name = $cmd['sudo'] ? 'sudo' : $program;

            return ['deny', "{$name} is the main session's. For your card's stack use vendor/bin/kanban stack status|wait|logs|up; ask the main session for anything else."];
        }

        if ($program === 'git') {
            return $this->git($cmd, $base);
        }

        $kanban = $this->kanbanArgs($cmd);
        if ($kanban !== null) {
            return $this->kanban($kanban);
        }

        if ($this->isKanban() && $dirWhere === Locations::OWN && $this->isDbReset($cmd) && $this->dbPortsDiffer()) {
            return ['allow', 'kanban: database reset on the worktree\'s own database'];
        }

        $readOnly = in_array($program, self::READ_ONLY, true) && ! $outsideWrite && ! ($program === 'sed' && $this->writeTargets($cmd) !== []);

        if ($this->isKanban() && in_array($dirWhere, [Locations::MAIN, Locations::BOARD, Locations::GITDIR, Locations::OTHER], true) && ! $readOnly) {
            return ['deny', 'You are outside your worktree: only read-only commands run here. cd '.($this->own ?? '<your card\'s worktree, after EnterWorktree(path)>').' first.'];
        }

        return in_array($program, self::TRANSPARENT, true) && ! $outsideWrite && $this->isKanban() ? ['allow', ''] : [null, ''];
    }

    /**
     * @param  array<string, mixed>  $cmd
     * @return array{0: ?string, 1: string}
     */
    private function git(array $cmd, ?string $base): array
    {
        $git = GitCall::parse($cmd, $base, $this->home);

        if ($git->redirect !== null) {
            return ['deny', $this->gitRedirect($git->redirect)];
        }

        if ($git->class === GitCall::READ) {
            return $this->isKanban() ? ['allow', 'kanban: read-only git'] : [null, ''];
        }

        if ($git->class === GitCall::MAIN) {
            return ['deny', 'git '.($git->subcommand ?? '').' is the main session\'s (push, pull, fetch, stash, reset, checkout, switch, rebase, merge, branch/tag changes, worktree). You may use read-only git and git add/commit inside your own worktree; report anything else to the main session.'];
        }

        if ($this->actor === 'evaluator') {
            return ['deny', $this->evaluatorReadOnly()];
        }

        if ($git->force) {
            return ['deny', 'git add -f is denied: ignored files stay ignored. If one must be tracked, say so in your report.'];
        }

        if ($git->noVerify) {
            return ['deny', 'Commit hooks must run: drop -n/--no-verify and fix what the hook reports.'];
        }

        $where = $base === null ? Locations::MAIN : $this->where($base);

        if ($where !== Locations::OWN) {
            return ['deny', 'git '.$git->subcommand.' runs only inside your own worktree: cd '.($this->own ?? '<your worktree>').' first'.($this->own === null && $this->actor === 'worker' ? ' (EnterWorktree(path) with your card\'s worktree)' : '').'.'];
        }

        return $this->actor === 'worker' ? ['allow', 'kanban: commit in the bound worktree'] : [null, ''];
    }

    /**
     * @param  list<string>  $args
     * @return array{0: ?string, 1: string}
     */
    private function kanban(array $args): array
    {
        $positional = array_values(array_filter($args, fn (string $a): bool => ! str_starts_with($a, '-')));
        $sub = $positional[0] ?? 'help';
        $allow = $this->isKanban() ? ['allow', 'kanban: allowed CLI command'] : [null, ''];

        if (in_array($sub, self::KANBAN_READ, true)) {
            return $allow;
        }

        $readList = 'list, show, status, context, next';
        $mine = match ($this->actor) {
            'worker' => 'report',
            'evaluator' => 'verdict',
            default => null,
        };

        if ($sub === 'stack' && in_array($positional[1] ?? 'status', self::STACK_READ, true)) {
            return $allow;
        }

        $own = $sub === $mine || ($sub === 'stack' && ($positional[1] ?? null) === 'up' && $mine !== null);

        if (! $own) {
            $yours = $mine === null ? $readList.', stack status|wait|logs' : "{$readList}, {$mine} <your card>, stack status|wait|logs|up";

            return ['deny', "vendor/bin/kanban {$sub} is the main session's. You may run: {$yours}."];
        }

        $card = $this->binding['card'] ?? null;

        if (! is_string($card) || $card === '' || $this->own === null) {
            return ['deny', 'You are not bound to a card yet: call EnterWorktree(path) with your card\'s worktree first.'];
        }

        foreach (array_slice($positional, 1) as $a) {
            if (preg_match(self::ID, $a) && ! str_starts_with(strtoupper($card), strtoupper($a))) {
                return ['deny', "You work on {$card} only: vendor/bin/kanban {$sub} {$card} …"];
            }
        }

        return $allow;
    }

    /**
     * The CLI arguments when the command runs vendor/bin/kanban or `php artisan kanban:*`.
     *
     * @param  array<string, mixed>  $cmd
     * @return list<string>|null
     */
    private function kanbanArgs(array $cmd): ?array
    {
        $values = array_map(fn (array $w): string => $w['v'], $cmd['words']);

        if ($cmd['program'] === 'php') {
            $values = array_slice($values, 1);
            while ($values !== [] && str_starts_with($values[0], '-')) {
                $option = array_shift($values);
                if (in_array($option, ['-d', '-c', '-z'], true)) {
                    array_shift($values);
                }
            }
        }

        if ($values === []) {
            return null;
        }

        $script = basename($values[0]);

        if ($script === 'kanban') {
            return array_slice($values, 1);
        }

        if ($script === 'artisan' && str_starts_with($values[1] ?? '', 'kanban:')) {
            return [substr($values[1], 7), ...array_slice($values, 2)];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $cmd
     */
    private function isDbReset(array $cmd): bool
    {
        $values = array_map(fn (array $w): string => $w['v'], $cmd['words']);
        $at = array_search('artisan', array_map('basename', $values), true);

        return $at !== false && in_array($values[$at + 1] ?? '', ['migrate', 'migrate:fresh', 'db:wipe', 'db:seed'], true);
    }

    private function dbPortsDiffer(): bool
    {
        $read = function (string $file): ?string {
            $env = @file_get_contents($file);

            return $env !== false && preg_match('/^DB_PORT=["\']?([^"\'\s]*)/m', $env, $m) ? $m[1] : null;
        };

        $worktree = $read($this->own.'/.env');

        return $worktree !== null && $worktree !== '' && $worktree !== $read($this->locations->main.'/.env');
    }

    /**
     * Words the command writes to: output redirections and the file operands of writing programs.
     *
     * @param  array<string, mixed>  $cmd
     * @return list<array<string, mixed>>
     */
    private function writeTargets(array $cmd): array
    {
        $targets = [];

        foreach ($cmd['redirs'] as $redirect) {
            $op = $redirect['v'];
            $isFd = preg_match('/^(\d+|-)$/', $redirect['target']['v']);
            if (in_array($op, ['>', '>>', '>|', '&>', '&>>', '<>'], true) || ($op === '>&' && ! $isFd)) {
                $targets[] = $redirect['target'];
            }
        }

        $args = array_slice($cmd['words'], 1);
        $operands = fn (array $withValue): array => self::operands($args, $withValue);

        $programTargets = match ($cmd['program']) {
            'tee', 'mv', 'rm', 'rmdir', 'unlink', 'touch', 'mkdir' => $operands(['-m', '--mode', '-d', '-t', '-r', '--reference', '-S', '--suffix']),
            'truncate' => $operands(['-s', '--size', '-r', '--reference']),
            'chmod', 'chown', 'chgrp' => array_slice($operands(['--reference']), 1),
            'cp', 'install', 'ln', 'rsync' => $this->destination($args),
            'sed' => $this->sedTargets($args),
            'dd' => array_values(array_map(
                fn (array $w): array => [...$w, 'v' => substr($w['v'], 3), 'plain' => substr($w['plain'], 3)],
                array_filter($args, fn (array $w): bool => str_starts_with($w['v'], 'of=')),
            )),
            'find' => $this->findTargets($args),
            default => [],
        };

        if (in_array($cmd['program'], ['mv', 'cp', 'install', 'ln'], true)) {
            foreach ($args as $k => $word) {
                if (in_array($word['v'], ['-t', '--target-directory'], true) && isset($args[$k + 1])) {
                    $programTargets[] = $args[$k + 1];
                } elseif (str_starts_with($word['v'], '--target-directory=')) {
                    $programTargets[] = [...$word, 'v' => substr($word['v'], 19), 'plain' => substr($word['plain'], 19)];
                }
            }
        }

        return [...$targets, ...$programTargets];
    }

    /**
     * @param  list<array<string, mixed>>  $args
     * @param  list<string>  $withValue
     * @return list<array<string, mixed>>
     */
    private static function operands(array $args, array $withValue): array
    {
        $operands = [];
        $options = true;
        $count = count($args);

        for ($k = 0; $k < $count; $k++) {
            $v = $args[$k]['v'];

            if ($options && $v === '--') {
                $options = false;
            } elseif ($options && str_starts_with($v, '-') && $v !== '-') {
                $k += in_array($v, $withValue, true) ? 1 : 0;
            } else {
                $operands[] = $args[$k];
            }
        }

        return $operands;
    }

    /**
     * @param  list<array<string, mixed>>  $args
     * @return list<array<string, mixed>>
     */
    private function destination(array $args): array
    {
        $operands = self::operands($args, ['-S', '--suffix', '-m', '--mode', '-o', '--owner', '-g', '--group', '-e', '--rsh', '-t', '--target-directory']);

        return count($operands) >= 2 ? [end($operands)] : [];
    }

    /**
     * @param  list<array<string, mixed>>  $args
     * @return list<array<string, mixed>>
     */
    private function sedTargets(array $args): array
    {
        $inPlace = false;
        $script = false;

        foreach ($args as $word) {
            $v = $word['v'];
            $inPlace = $inPlace || str_starts_with($v, '--in-place') || (preg_match('/^-[a-zA-Z]+/', $v) && ! str_starts_with($v, '--') && str_contains(explode('.', $v)[0], 'i'));
            $script = $script || in_array($v, ['-e', '-f', '--expression', '--file'], true) || str_starts_with($v, '--expression=');
        }

        if (! $inPlace) {
            return [];
        }

        $operands = self::operands($args, ['-e', '-f', '--expression', '--file', '-l', '--line-length']);

        return $script ? $operands : array_slice($operands, 1);
    }

    /**
     * @param  list<array<string, mixed>>  $args
     * @return list<array<string, mixed>>
     */
    private function findTargets(array $args): array
    {
        $targets = [];
        $starts = [];

        foreach ($args as $k => $word) {
            if ($targets === [] && $starts === [] && preg_match('/^[-(!]/', $word['v']) === 0) {
                $starts[] = $word;
            }
            if (in_array($word['v'], ['-fprint', '-fprint0', '-fprintf', '-fls'], true) && isset($args[$k + 1])) {
                $targets[] = $args[$k + 1];
            }
        }

        $delete = array_filter($args, fn (array $w): bool => $w['v'] === '-delete') !== [];

        if ($delete) {
            foreach ($args as $word) {
                if (preg_match('/^[-(!]/', $word['v'])) {
                    break;
                }
                $targets[] = $word;
            }
            if ($targets === []) {
                $targets[] = ['t' => 'w', 'v' => '.', 'dyn' => false, 'q' => false, 'plain' => '.', 'subs' => []];
            }
        }

        return $targets;
    }

    private function where(string $path): string
    {
        return $this->locations->classify($path, $this->own, $this->actor === 'other');
    }

    private function isKanban(): bool
    {
        return $this->actor === 'worker' || $this->actor === 'evaluator';
    }

    private function absolute(string $path, string $base): string
    {
        if ($path === '~' || str_starts_with($path, '~/')) {
            $path = $this->home.substr($path, 1);
        }

        return ShellParser::normalize($path[0] === '/' ? $path : $base.'/'.$path);
    }

    private function strictAllows(string $path): bool
    {
        $guard = $this->config()['guard'] ?? [];

        if (empty($guard['strict'])) {
            return true;
        }

        $relative = substr($this->locations->canonical($this->absolute($path, $this->cwd)), strlen($this->locations->main) + 1);

        foreach ($guard['main_write_paths'] ?? [] as $glob) {
            $regex = '#^'.strtr(preg_quote((string) $glob, '#'), ['\*\*' => '.*', '\*' => '[^/]*', '\?' => '[^/]']).'$#';
            if (preg_match($regex, $relative)) {
                return true;
            }
        }

        return false;
    }

    private function enterFirst(): string
    {
        return $this->own !== null
            ? "Work only inside your card's worktree {$this->own}: use paths under it."
            : "Code work happens in your card's worktree: call EnterWorktree(path: \"{$this->locations->main}/.claude/worktrees/<card id>\") first, then edit there.";
    }

    private function evaluatorReadOnly(): string
    {
        return 'The evaluator is read-only: never edit or commit. Record findings with vendor/bin/kanban verdict <ID> ….';
    }

    private function unverifiable(string $why): string
    {
        return "kanban guard cannot verify this command ({$why}): split it into plain, separate commands.";
    }

    private function gitRedirect(string $how): string
    {
        return "{$how} is not allowed for subagents: cd into your own worktree and run plain git there (no -C, --git-dir, --work-tree, -c or GIT_* variables).";
    }

    /**
     * @return array<string, mixed>
     */
    /** The Boost-installed skill (a name in boost.json `skills`) that $path lies in under some `.claude/skills/`, or null. */
    private function managedSkill(string $path): ?string
    {
        if (preg_match('#/\.claude/skills/([^/]+)(/|$)#', $this->locations->canonical($path), $m) !== 1) {
            return null;
        }
        if ($this->boostSkills === null) {
            $boost = json_decode((string) @file_get_contents($this->locations->main.'/boost.json'), true);
            $this->boostSkills = array_values(array_filter((array) ($boost['skills'] ?? []), 'is_string'));
        }

        return in_array($m[1], $this->boostSkills, true) ? $m[1] : null;
    }

    private function managedSkillReason(string $skill): string
    {
        return ".claude/skills/{$skill} is installed by Boost and boost:update overwrites it: change its source instead (.ai/skills/{$skill} for a project skill); a package's skill is fixed in that package, so report it with --discovered.";
    }

    private function config(): array
    {
        if ($this->config === null) {
            $json = @file_get_contents($this->locations->board.'/kanban.json');
            $this->config = $json === false ? [] : (json_decode($json, true) ?: []);
        }

        return $this->config;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function cards(): array
    {
        if ($this->cards === null) {
            $this->cards = [];
            foreach (glob($this->locations->board.'/*/*/*.json') ?: [] as $file) {
                if (basename($file) === 'board.json') {
                    continue;
                }
                $card = json_decode((string) file_get_contents($file), true);
                if (is_array($card) && isset($card['id'])) {
                    $this->cards[strtoupper($card['id'])] = $card;
                }
            }
        }

        return $this->cards;
    }

    private function candidates(string $stage): string
    {
        $list = [];

        foreach ($this->cards() as $card) {
            if (($card['stage'] ?? null) === $stage && ! empty($card['work']['worktree'])) {
                $list[] = $card['id'].' → '.$this->locations->main.'/'.$card['work']['worktree'];
            }
        }

        return $list === [] ? 'none' : implode(', ', $list);
    }

    /**
     * Agents whose heartbeat (file mtime) is within stale_after_minutes and that have not stopped.
     *
     * @return list<array{agent_id: string, agent_type: string, card: ?string, target: ?string}>
     */
    private function liveAgents(): array
    {
        $stale = (int) ($this->config()['stale_after_minutes'] ?? 20) * 60;
        $live = [];

        foreach (glob($this->agentsDir().'/*.json') ?: [] as $file) {
            $agent = json_decode((string) @file_get_contents($file), true);

            if (! is_array($agent) || ! empty($agent['stopped_at']) || (int) @filemtime($file) < time() - $stale) {
                continue;
            }

            $worktree = $agent['worktree'] ?? null;
            $live[] = [
                'agent_id' => (string) ($agent['agent_id'] ?? basename($file, '.json')),
                'agent_type' => (string) ($agent['agent_type'] ?? ''),
                'card' => isset($agent['card']) ? strtoupper((string) $agent['card']) : null,
                'target' => is_string($worktree) && $worktree !== '' ? $this->locations->canonical($this->absolute($worktree, $this->locations->main)) : null,
            ];
        }

        return $live;
    }

    /**
     * The pending spawn the WorktreeCreate hook hands to the next `isolation: worktree` agent (spawns/<ID>.json).
     *
     * @param  array<string, mixed>  $card
     */
    private function recordSpawn(array $card, string $type): void
    {
        $dir = $this->locations->main.'/.git/laravel-kanban/spawns';
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $file = $dir.'/'.$card['id'].'.json';
        $tmp = $file.'.'.getmypid().'.tmp';
        file_put_contents($tmp, json_encode(['card' => $card['id'], 'agent_type' => $type, 'worktree' => $card['work']['worktree'], 'at' => microtime(true)], JSON_UNESCAPED_SLASHES)."\n");
        rename($tmp, $file);
    }

    /**
     * @param  array<string, mixed>  $card
     */
    private function bind(array $card): void
    {
        $dir = $this->agentsDir();
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $binding = [
            'agent_id' => $this->agentId,
            'agent_type' => $this->agentType,
            'card' => $card['id'],
            'worktree' => $card['work']['worktree'],
            'bound_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.vP'),
            'stopped_at' => null,
            'stop_blocks' => (int) ($this->binding['stop_blocks'] ?? 0),
        ];

        $file = $dir.'/'.$this->agentId.'.json';
        $tmp = $file.'.'.getmypid().'.tmp';
        file_put_contents($tmp, json_encode($binding, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
        rename($tmp, $file);
    }
}
