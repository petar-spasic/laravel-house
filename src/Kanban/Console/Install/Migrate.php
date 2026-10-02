<?php

namespace PetarSpasic\LaravelHouse\Kanban\Console\Install;

use PetarSpasic\LaravelHouse\Kanban\Code\EnvWriter;
use PetarSpasic\LaravelHouse\Kanban\Code\PortRegistry;
use PetarSpasic\LaravelHouse\Kanban\Store\Git\Bootstrap;
use PetarSpasic\LaravelHouse\Kanban\Store\Snapshot;
use PetarSpasic\LaravelHouse\Kanban\Support\Git;
use PetarSpasic\LaravelHouse\Kanban\Support\Json;
use PetarSpasic\LaravelHouse\Kanban\Support\Lock;
use PetarSpasic\LaravelHouse\Kanban\Support\Paths;

/**
 * Moves a project off the names the board stored as the separate petar-spasic/laravel-kanban package: the runtime
 * directory, the machine's port registry, the markers in CLAUDE.md, the agents and worktree `.env` files, core.hooksPath,
 * the boost.json entry and the deploy-key path in the local compose file. Runs before `attach` in `kanban:install`,
 * `attach` and `doctor --fix`; `doctor` warns while anything is left. Delete it once every project is upgraded.
 */
final class Migrate extends Step
{
    public const PACKAGE = 'petar-spasic/laravel-kanban';

    public const RUNTIME = '.git/laravel-kanban';

    public const STATE = 'laravel-kanban';

    public const HOOKS_PATH = 'vendor/petar-spasic/laravel-kanban/githooks';

    /** Runtime files never dropped for the current copy. */
    private const PRECIOUS = ['deploy_key', 'deploy_key.pub', 'journal.jsonl'];

    /** Old marker => current marker, in every file that carries one. */
    public const MARKERS = [
        '<!-- laravel-kanban:start -->' => Guidelines::START,
        '<!-- laravel-kanban:end -->' => Guidelines::END,
        '<!-- laravel-kanban:agent' => ClaudeAgents::MARKER,
        '# laravel-kanban worktree stack (managed: rewritten by kanban, edit config/kanban.php stack.env instead)' => EnvWriter::MARKER,
    ];

    public function run(bool $dryRun = false, bool $force = false): array
    {
        if (! is_dir($this->paths->gitDir())) {
            return [];
        }

        return [
            ...$this->runtime($dryRun),
            ...$this->registry($dryRun),
            ...$this->markers($dryRun),
            ...$this->hooksPath($dryRun),
            ...$this->boost($dryRun),
            ...($this->blocked() && ! $dryRun ? [] : $this->compose($dryRun)),
        ];
    }

    /**
     * The old runtime directory is still there (agents running, a held lock, or a file it keeps), so the deploy key the
     * repository knows may still live in it: `attach` must not run, or it makes a second, unregistered key.
     */
    public function blocked(): bool
    {
        return is_dir($this->path(self::RUNTIME));
    }

    public function check(): array
    {
        if (! is_dir($this->paths->gitDir())) {
            return [];
        }
        $left = [];
        if (is_dir($this->path(self::RUNTIME))) {
            $left[] = self::RUNTIME;
        }
        if (($registry = $this->oldRegistry()) !== null && is_file($registry.'/stacks.json')) {
            $users = $this->oldPackageUsers($registry);
            $left[] = "{$registry}/stacks.json".($users === [] ? '' : ', still used by '.implode(', ', $users));
        }
        array_push($left, ...$this->markedFiles());
        if ($this->gitHooksPath() === self::HOOKS_PATH) {
            $left[] = 'core.hooksPath '.self::HOOKS_PATH;
        }
        if (in_array(self::PACKAGE, $this->boostPackages(), true)) {
            $left[] = 'boost.json packages '.self::PACKAGE;
        }
        if (($compose = $this->composeFile()) !== null && str_contains((string) $this->read($compose), self::RUNTIME.'/')) {
            $left[] = $compose;
        }

        return array_map(fn (string $item) => ['warn', "old laravel-kanban name: {$item} (run `vendor/bin/kanban doctor --fix`)"], $left);
    }

    /** @return list<string> */
    private function runtime(bool $dryRun): array
    {
        $old = $this->path(self::RUNTIME);
        if (! is_dir($old)) {
            return [];
        }
        $new = $this->paths->runtime();
        $working = $this->workingAgents($old);
        if ($working > 0) {
            return ['kept '.self::RUNTIME.": {$working} agent(s) still working; stop them, then run `vendor/bin/kanban doctor --fix`"];
        }
        if ($dryRun) {
            return ['would move '.self::RUNTIME.' to '.Paths::RUNTIME];
        }
        $lock = Lock::try($old.'/lock');
        if ($lock === null) {
            return ['kept '.self::RUNTIME.': a board write holds its lock; run `vendor/bin/kanban doctor --fix` again'];
        }
        try {
            if (! is_dir($new)) {
                $lock->release();
                rename($old, $new);

                return ['moved '.self::RUNTIME.' to '.Paths::RUNTIME];
            }
            $kept = $this->merge($old, $new, '');
        } finally {
            $lock->release();
        }
        if ($kept === []) {
            @unlink($old.'/lock');
            @rmdir($old);

            return ['moved '.self::RUNTIME.' into '.Paths::RUNTIME];
        }

        return ['moved '.self::RUNTIME.' into '.Paths::RUNTIME.'; kept '.implode(', ', $kept).' (a different copy is in '.Paths::RUNTIME.'): delete '.self::RUNTIME.' once nothing there is needed'];
    }

    /**
     * Moves what $new lacks, directory by directory. Where both hold a file, the current one wins, except the deploy
     * key and the journal of uncommitted UI writes: those stay in $old unless both copies are the same.
     *
     * @return list<string> entries left in $old
     */
    private function merge(string $old, string $new, string $prefix): array
    {
        $kept = [];
        foreach (array_diff(scandir($old) ?: [], ['.', '..', 'lock']) as $entry) {
            [$from, $to] = [$old.'/'.$entry, $new.'/'.$entry];
            if (! file_exists($to)) {
                rename($from, $to);
            } elseif (is_dir($from) && is_dir($to)) {
                array_push($kept, ...$this->merge($from, $to, $prefix.$entry.'/'));
                @rmdir($from);
            } elseif (is_dir($from) || is_dir($to) || (in_array($prefix.$entry, self::PRECIOUS, true) && file_get_contents($from) !== file_get_contents($to))) {
                $kept[] = $prefix.$entry;
            } else {
                unlink($from);
            }
        }

        return $kept;
    }

    private function workingAgents(string $runtime): int
    {
        $stale = 60 * Snapshot::staleMinutesOf((array) json_decode((string) $this->read(Paths::BOARD.'/kanban.json'), true));
        $working = 0;
        foreach (glob($runtime.'/agents/*.json') ?: [] as $file) {
            // After the upgrade the guard and the hooks update the current runtime's copy of the record.
            $current = $this->paths->agents(basename($file, '.json'));
            $file = is_file($current) ? $current : $file;
            $agent = json_decode((string) @file_get_contents($file), true);
            if (is_array($agent) && empty($agent['stopped_at']) && time() - (int) @filemtime($file) <= $stale) {
                $working++;
            }
        }

        return $working;
    }

    /**
     * The machine-wide registry moves only once no repo it lists still runs the old package: until then, that repo
     * allocates from the old file and a move would hand out its slots twice. Into an existing registry, each old
     * entry joins unless its slot is taken.
     *
     * @return list<string>
     */
    private function registry(bool $dryRun): array
    {
        $old = $this->oldRegistry();
        if ($old === null || ! is_file($old.'/stacks.json')) {
            return [];
        }
        if (($users = $this->oldPackageUsers($old)) !== []) {
            return ["kept {$old}/stacks.json: still used by ".implode(', ', $users).' (on '.self::PACKAGE.'); upgrade those, then run `vendor/bin/kanban doctor --fix`'];
        }
        $new = (new PortRegistry((array) ($this->config['stack'] ?? [])))->dir();
        if ($dryRun) {
            return ["would move {$old}/stacks.json to {$new}"];
        }
        $oldLock = Lock::exclusive($old.'/stacks.lock');
        $newLock = Lock::exclusive($new.'/stacks.lock');
        try {
            if (! is_file($new.'/stacks.json')) {
                rename($old.'/stacks.json', $new.'/stacks.json');
                $taken = [];
            } else {
                $into = json_decode((string) file_get_contents($new.'/stacks.json'), true);
                $registered = array_column((array) ($into['stacks'] ?? []), 'worktree');
                $left = [];
                foreach (self::stacksOf($old) as $slot => $entry) {
                    if (in_array($entry['worktree'] ?? null, $registered, true)) {
                        continue;
                    }
                    if (isset($into['stacks'][$slot])) {
                        $left[$slot] = $entry;
                    } else {
                        $into['stacks'][$slot] = $entry;
                    }
                }
                Json::write($new.'/stacks.json', self::registryJson($into));
                $taken = array_map('strval', array_keys($left));
                if ($left === []) {
                    unlink($old.'/stacks.json');
                } else {
                    $remaining = json_decode((string) file_get_contents($old.'/stacks.json'), true);
                    $remaining['stacks'] = $left;
                    Json::write($old.'/stacks.json', self::registryJson($remaining));
                }
            }
        } finally {
            $newLock->release();
            $oldLock->release();
        }
        if ($taken !== []) {
            return ["merged {$old}/stacks.json into {$new}; kept slot(s) ".implode(', ', $taken).' (taken there too): stop those stacks, then delete '.$old];
        }
        @unlink($old.'/stacks.lock');
        @rmdir($old);

        return ["moved {$old}/stacks.json to {$new}"];
    }

    /** @return list<string> repos listed in the old registry that still have the old package in vendor */
    private function oldPackageUsers(string $old): array
    {
        $repos = array_unique(array_filter(array_map(fn (mixed $entry) => is_array($entry) ? ($entry['repo'] ?? null) : null, self::stacksOf($old)), 'is_string'));

        return array_values(array_filter($repos, fn (string $repo) => is_dir($repo.'/vendor/'.self::PACKAGE)));
    }

    /** @param  array<string, mixed>  $registry */
    private static function registryJson(array $registry): string
    {
        return json_encode($registry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
    }

    /** @return array<array-key, mixed> */
    private static function stacksOf(string $dir): array
    {
        $registry = json_decode((string) @file_get_contents($dir.'/stacks.json'), true);

        return is_array($registry) && is_array($registry['stacks'] ?? null) ? $registry['stacks'] : [];
    }

    /** The old machine-wide registry directory, or null when `KANBAN_STATE_DIR` names one explicitly. */
    private function oldRegistry(): ?string
    {
        $state = getenv('KANBAN_STATE_DIR');
        if (is_string($state) && $state !== '') {
            return null;
        }

        return dirname((new PortRegistry((array) ($this->config['stack'] ?? [])))->dir()).'/'.self::STATE;
    }

    /** @return list<string> */
    private function markers(bool $dryRun): array
    {
        $lines = [];
        foreach ($this->markedFiles() as $file) {
            if ($dryRun) {
                $lines[] = "would rename the markers in {$file}";

                continue;
            }
            $this->write($file, strtr((string) $this->read($file), self::MARKERS));
            $lines[] = "renamed the markers in {$file}";
        }

        return $lines;
    }

    /** @return list<string> files (relative to main) that still carry an old marker */
    private function markedFiles(): array
    {
        $candidates = ['CLAUDE.md'];
        foreach (ClaudeAgents::AGENTS as $agent) {
            $candidates[] = ".claude/agents/{$agent}.md";
        }
        foreach (glob($this->path(Paths::WORKTREES).'/*/.env') ?: [] as $env) {
            $candidates[] = substr($env, strlen($this->paths->main) + 1);
        }

        return array_values(array_filter($candidates, function (string $file) {
            $content = $this->read($file);

            return $content !== null && strtr($content, self::MARKERS) !== $content;
        }));
    }

    /** @return list<string> */
    private function hooksPath(bool $dryRun): array
    {
        if ($this->gitHooksPath() !== self::HOOKS_PATH) {
            return [];
        }
        if ($dryRun) {
            return ['would set core.hooksPath to '.Bootstrap::HOOKS_PATH];
        }
        (new Git($this->paths->main))->run(['config', 'core.hooksPath', Bootstrap::HOOKS_PATH]);

        return ['core.hooksPath: '.Bootstrap::HOOKS_PATH];
    }

    private function gitHooksPath(): ?string
    {
        return (new Git($this->paths->main))->line(['config', '--get', 'core.hooksPath']);
    }

    /** @return list<string> */
    private function boost(bool $dryRun): array
    {
        if (! in_array(self::PACKAGE, $this->boostPackages(), true)) {
            return [];
        }
        if ($dryRun) {
            return ['would remove '.self::PACKAGE.' from boost.json packages'];
        }
        $current = (string) $this->read('boost.json');
        $config = json_decode($current);
        $config->packages = array_values(array_filter($config->packages, fn (mixed $package) => $package !== self::PACKAGE));
        $this->write('boost.json', self::json($config, $current));

        return ['boost.json packages -= '.self::PACKAGE];
    }

    /** @return list<mixed> */
    private function boostPackages(): array
    {
        $config = json_decode((string) $this->read('boost.json'));

        return is_object($config) && is_array($config->packages ?? null) ? $config->packages : [];
    }

    /** @return list<string> */
    private function compose(bool $dryRun): array
    {
        $file = $this->composeFile();
        $current = $file === null ? null : $this->read($file);
        if ($current === null || ! str_contains($current, self::RUNTIME.'/')) {
            return [];
        }
        if ($dryRun) {
            return ["would point {$file} at ".Paths::RUNTIME];
        }
        $this->write($file, str_replace(self::RUNTIME.'/', Paths::RUNTIME.'/', $current));

        return ["pointed {$file} at ".Paths::RUNTIME.' (recreate the stack: `docker compose -f '.$file.' up -d --wait`)'];
    }

    private function composeFile(): ?string
    {
        $file = $this->config['stack']['compose_file'] ?? null;

        return is_string($file) && $file !== '' ? $file : null;
    }
}
