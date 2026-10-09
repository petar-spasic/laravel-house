<?php

namespace PetarSpasic\LaravelHouse\Tests\Support;

use RuntimeException;
use Symfony\Component\Process\Process;

/** A bare repository standing in for the network remote. */
final class Origin
{
    public function __construct(public readonly string $path) {}

    public static function create(): self
    {
        $path = Sandbox::tmp().'/origin.git';
        (new Process(['git', 'init', '-q', '--bare', '-b', 'main', $path]))->mustRun();

        return new self($path);
    }

    /**
     * An origin with an installed, published board, plus two attached clones.
     *
     * @return array{0: self, 1: Sandbox, 2: Sandbox, 3: Sandbox} origin, a, b and the seed that published it
     */
    public static function published(): array
    {
        $origin = self::create();
        $seed = Sandbox::create('seed')->addRemote($origin);
        $seed->install('ACME');
        $seed->git('commit', '-q', '-am', 'Ignore the board worktree');
        $seed->git('push', '-q', 'origin', 'main');
        if (($out = $seed->ok('sync')) !== "sync: pushed 1 commit(s)\n") {
            throw new RuntimeException("publishing the board: {$out}");
        }
        $a = $origin->clone('a');
        $b = $origin->clone('b');
        foreach ([$a, $b] as $clone) {
            if (! str_contains($out = $clone->ok('attach'), 'attached origin/kanban at docs/kanban')) {
                throw new RuntimeException("attaching {$clone->root}: {$out}");
            }
        }

        return [$origin, $a, $b, $seed];
    }

    /**
     * A pre-push hook for $clone that runs $script (a shell snippet) once, before the first push goes out; on every push
     * with $every. With $ref, only a push to that remote ref counts, and $script reads the push's lines on its stdin as
     * it would without one.
     */
    public static function racingPush(Sandbox $clone, string $script, bool $every = false, ?string $ref = null): string
    {
        $hooks = Sandbox::tmp();
        $filter = $ref === null ? '' : "input=\$(cat)\nprintf '%s\\n' \"\$input\" | awk '{ print \$3 }' | grep -qxF '{$ref}' || exit 0\n";
        $once = $every ? '' : "[ -f \"{$hooks}/done\" ] && exit 0\ntouch \"{$hooks}/done\"\n";
        $run = $ref === null ? $script : "{\n{$script}\n} <<PUSHED\n\$input\nPUSHED";
        file_put_contents("{$hooks}/pre-push", "#!/bin/sh\n{$filter}{$once}{$run}\nexit 0\n");
        chmod("{$hooks}/pre-push", 0755);
        $clone->git('config', 'core.hooksPath', $hooks);

        return $hooks;
    }

    /** A fresh clone with its own vendor/bin/kanban (not attached yet). */
    public function clone(string $name): Sandbox
    {
        $root = Sandbox::tmp().'/'.$name;
        (new Process(['git', 'clone', '-q', $this->path, $root]))->mustRun();
        $sandbox = new Sandbox($root);
        $sandbox->configure();
        $sandbox->vendorBin();

        return $sandbox;
    }

    /** Commit subjects on a branch of the origin, newest first. */
    public function log(string $branch): array
    {
        $process = new Process(['git', '--git-dir='.$this->path, 'log', '--format=%s', $branch]);
        $process->run();

        return array_values(array_filter(explode("\n", $process->getOutput())));
    }

    public function show(string $rev): string
    {
        return (new Process(['git', '--git-dir='.$this->path, 'show', $rev]))->mustRun()->getOutput();
    }
}
