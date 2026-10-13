<?php

namespace PetarSpasic\LaravelHouse\Kanban\Console\Install;

use PetarSpasic\LaravelHouse\Kanban\Code\EnvWriter;
use PetarSpasic\LaravelHouse\Kanban\Code\Worktrees;

/**
 * The local compose file's card-stack lines, added in place to a file written before them: the card's directory
 * mounted at its host path (where its agents' shells run), its `.tmp` as TMPDIR, the board sync's ssh command
 * as a variable a card stack sets empty, and, once main holds Dockerfile.agent, the app's Dockerfile as the variable a
 * card stack sets to it. A file whose anchors are not the template's is left for a hand edit.
 */
final class Compose extends Step
{
    private const ANCHOR_MOUNT = '#^([ \t]+)- \./?:/app[ \t]*$#m';

    private const ANCHOR_SSH = '#^([ \t]+)GIT_SSH_COMMAND:[ \t]*(?![ \t]*\$\{)(["\']?)(ssh -i [^\n]*?)\2[ \t]*$#m';

    private const ANCHOR_DOCKERFILE = '#^([ \t]+)dockerfile:[ \t]*(["\']?)Dockerfile\.local\2[ \t]*$#m';

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
        // main's .env never sets the variable, so main's stack needs no recreate for it
        $recreate = $done !== [];
        if ($this->dockerfileFixable($next)) {
            [$at, $block] = $this->serviceBlock($next);
            $patched = preg_replace_callback(self::ANCHOR_DOCKERFILE, fn (array $m) => "{$m[1]}dockerfile: \${".EnvWriter::DOCKERFILE.':-Dockerfile.local}', $block, 1);
            $next = substr($next, 0, $at).$patched.substr($next, $at + strlen($block));
            $done[] = 'the app\'s Dockerfile as '.EnvWriter::DOCKERFILE;
        }
        if ($done === []) {
            return [];
        }
        if ($dryRun) {
            return ["would add to {$file}: ".implode('; ', $done)];
        }
        $this->write($file, $next);

        return ["added to {$file}: ".implode('; ', $done).($recreate ? ' (recreate main\'s stack: `docker compose -f '.$file.' up -d --wait`)'
            : ' (commit it: card and merge stacks read the compose file from main\'s commits)')];
    }

    public function check(): array
    {
        $file = $this->file();
        $current = $file === null ? null : $this->read($file);
        if ($current === null) {
            return [];
        }
        $results = $this->checkDockerfile($file, $current);
        if (($this->config['agents']['shell'] ?? 'container') === 'host') {
            return $results;
        }
        $missing = array_keys(array_filter([
            '- ./:${'.Worktrees::MOUNT.':-/app} in the app service\'s volumes' => ! str_contains($current, Worktrees::MOUNT),
            'TMPDIR: ${KANBAN_TMPDIR:-/tmp} in its environment' => ! str_contains($current, 'KANBAN_TMPDIR'),
            'GIT_SSH_COMMAND: ${KANBAN_GIT_SSH_COMMAND-…}' => str_contains($current, 'GIT_SSH_COMMAND') && ! str_contains($current, 'KANBAN_GIT_SSH_COMMAND'),
        ]));
        if ($missing === []) {
            return [...$results, ['ok', "{$file} carries the card-stack lines"]];
        }
        $fixable = preg_match(self::ANCHOR_SSH, $current) === 1 || (! str_contains($current, Worktrees::MOUNT) && preg_match(self::ANCHOR_MOUNT, $current) === 1);

        return [...$results, ['warn', "{$file} lacks ".implode(', ', $missing).': card agents\' shells run on this machine, not in their stack ('
            .($fixable ? '`vendor/bin/kanban doctor --fix` adds them' : 'add them by hand; the anchors are not the template\'s').')']];
    }

    /** @return list<array{0: 'ok'|'warn', 1: string}> */
    private function checkDockerfile(string $file, string $current): array
    {
        $reads = str_contains($current, EnvWriter::DOCKERFILE);
        if ($this->agentImage()) {
            $gaps = EnvWriter::agentImageGaps($this->paths->main);
            $together = EnvWriter::MERGED_TOGETHER;
            $last = array_pop($together);
            $results = $gaps === [] ? [] : [['warn', implode('; ', $gaps).': card and merge stacks keep Dockerfile.local until '
                .implode(', ', $together)." and {$last} are merged together from the laravel-deployment skill's templates (its Procedure)"]];
            if (! $reads) {
                return [['warn', "{$file} builds the app from a fixed Dockerfile, so card and merge stacks ignore "
                    .EnvWriter::AGENT_IMAGE.' ('.($this->dockerfileFixable($current)
                        ? '`vendor/bin/kanban doctor --fix` makes it `dockerfile: ${'.EnvWriter::DOCKERFILE.':-Dockerfile.local}`'
                        : 'set the app service\'s build to `dockerfile: ${'.EnvWriter::DOCKERFILE.':-Dockerfile.local}` by hand').')'], ...$results];
            }

            return $results === [] ? [['ok', "{$file} builds card and merge stacks from ".EnvWriter::AGENT_IMAGE]] : $results;
        }

        return $reads ? [['warn', EnvWriter::AGENT_IMAGE.' is missing, so card and merge stacks build from Dockerfile.local (the laravel-deployment skill ships it)']] : [];
    }

    /** Main holds the agent image and the `stack.service` service builds from Dockerfile.local in one line, the template's. */
    private function dockerfileFixable(string $yaml): bool
    {
        return $this->agentImage() && ! str_contains($yaml, EnvWriter::DOCKERFILE) && ($block = $this->serviceBlock($yaml)) !== null
            && preg_match_all(self::ANCHOR_DOCKERFILE, $block[1]) === 1;
    }

    /**
     * The `stack.service` service's lines after its key, those indented deeper than it, and their offset; null when no
     * single indented key names it.
     *
     * @return array{0: int, 1: string}|null
     */
    private function serviceBlock(string $yaml): ?array
    {
        $service = preg_quote((string) ($this->config['stack']['service'] ?? 'app'), '#');
        if (preg_match_all('#^([ \t]+)'.$service.':[ \t]*\n((?:(?:\1[ \t]+[^\n]*|[ \t]*)\n)*)#m', $yaml, $m, PREG_OFFSET_CAPTURE | PREG_SET_ORDER) !== 1) {
            return null;
        }

        return [$m[0][2][1], $m[0][2][0]];
    }

    private function agentImage(): bool
    {
        return is_file($this->path(EnvWriter::AGENT_IMAGE));
    }

    private function file(): ?string
    {
        $file = $this->config['stack']['compose_file'] ?? null;

        return is_string($file) && $file !== '' && is_file($this->path($file)) ? $file : null;
    }
}
