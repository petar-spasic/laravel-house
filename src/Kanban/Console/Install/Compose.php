<?php

namespace PetarSpasic\LaravelHouse\Kanban\Console\Install;

use PetarSpasic\LaravelHouse\Kanban\Code\Worktrees;

/**
 * The local compose file's card-stack lines, added in place to a file written before them: the card's directory
 * mounted at its host path (where its agents' shells run), its `.tmp` as TMPDIR, and the board sync's ssh command
 * as a variable a card stack sets empty. A file whose anchors are not the template's is left for a hand edit.
 */
final class Compose extends Step
{
    private const ANCHOR_MOUNT = '#^([ \t]+)- \./?:/app[ \t]*$#m';

    private const ANCHOR_SSH = '#^([ \t]+)GIT_SSH_COMMAND:[ \t]*(?![ \t]*\$\{)(["\']?)(ssh -i [^\n]*?)\2[ \t]*$#m';

    public function run(bool $dryRun = false, bool $force = false): array
    {
        $file = $this->file();
        $current = $file === null ? null : $this->read($file);
        if ($current === null) {
            return [];
        }
        $next = $current;
        $done = [];
        if (! str_contains($next, Worktrees::MOUNT) && preg_match(self::ANCHOR_MOUNT, $next) === 1) {
            $next = preg_replace_callback(self::ANCHOR_MOUNT, fn (array $m) => $m[0]."\n{$m[1]}- ./:\${".Worktrees::MOUNT.':-/app}', $next, 1);
            $done[] = 'the card mount';
        }
        if (preg_match(self::ANCHOR_SSH, $next) === 1) {
            $tmp = ! str_contains($next, 'KANBAN_TMPDIR');
            $next = preg_replace_callback(self::ANCHOR_SSH, fn (array $m) => ($tmp ? "{$m[1]}TMPDIR: \${KANBAN_TMPDIR:-/tmp}\n" : '')
                ."{$m[1]}GIT_SSH_COMMAND: \${KANBAN_GIT_SSH_COMMAND-{$m[3]}}", $next, 1);
            $done[] = 'the ssh command as KANBAN_GIT_SSH_COMMAND'.($tmp ? ' and TMPDIR' : '');
        }
        if ($done === []) {
            return [];
        }
        if ($dryRun) {
            return ["would add to {$file}: ".implode('; ', $done)];
        }
        $this->write($file, $next);

        return ["added to {$file}: ".implode('; ', $done).' (recreate main\'s stack: `docker compose -f '.$file.' up -d --wait`)'];
    }

    public function check(): array
    {
        $file = $this->file();
        $current = $file === null ? null : $this->read($file);
        if ($current === null || ($this->config['agents']['shell'] ?? 'container') === 'host') {
            return [];
        }
        $missing = array_keys(array_filter([
            '- ./:${'.Worktrees::MOUNT.':-/app} in the app service\'s volumes' => ! str_contains($current, Worktrees::MOUNT),
            'TMPDIR: ${KANBAN_TMPDIR:-/tmp} in its environment' => ! str_contains($current, 'KANBAN_TMPDIR'),
            'GIT_SSH_COMMAND: ${KANBAN_GIT_SSH_COMMAND-…}' => str_contains($current, 'GIT_SSH_COMMAND') && ! str_contains($current, 'KANBAN_GIT_SSH_COMMAND'),
        ]));
        if ($missing === []) {
            return [['ok', "{$file} carries the card-stack lines"]];
        }
        $fixable = $this->run(true) !== [];

        return [['warn', "{$file} lacks ".implode(', ', $missing).': card agents\' shells run on this machine, not in their stack ('
            .($fixable ? '`vendor/bin/kanban doctor --fix` adds them' : 'add them by hand; the anchors are not the template\'s').')']];
    }

    private function file(): ?string
    {
        $file = $this->config['stack']['compose_file'] ?? null;

        return is_string($file) && $file !== '' && is_file($this->path($file)) ? $file : null;
    }
}
