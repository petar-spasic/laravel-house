<?php

namespace PetarSpasic\LaravelHouse\Tests\Support;

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
