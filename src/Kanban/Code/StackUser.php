<?php

namespace PetarSpasic\LaravelHouse\Kanban\Code;

use PetarSpasic\LaravelHouse\Kanban\Support\DotEnv;

/**
 * The uid:gid a compose file runs the app as through `HOST_UID`/`HOST_GID` (`.env`, else the compose default), and the
 * checkout's user it must match: the container writes the bind-mounted checkout, its .git and the host's caches.
 */
final class StackUser
{
    private const KEYS = ['HOST_UID', 'HOST_GID'];

    public function __construct(private readonly string $main, private readonly string $composeFile) {}

    /** `uid:gid` of this process, which owns the checkout it runs from. */
    public static function caller(): string
    {
        return posix_geteuid().':'.posix_getegid();
    }

    /** `uid:gid` the compose file runs the app as, or null when it names no `HOST_UID`. */
    public function stack(): ?string
    {
        $yaml = (string) @file_get_contents($this->main.'/'.$this->composeFile);
        if (! str_contains($yaml, '${HOST_UID')) {
            return null;
        }
        $env = DotEnv::parse($this->main.'/.env');
        $ids = array_map(function (string $key) use ($env, $yaml) {
            $value = $env[$key] ?? null;

            return is_string($value) && $value !== '' ? $value : (preg_match('/\$\{'.$key.':-(\d+)\}/', $yaml, $m) ? $m[1] : '?');
        }, self::KEYS);

        return implode(':', $ids);
    }

    /** The mismatch with its fix, or null when the stack runs as the checkout's user (or names no user). */
    public function problem(): ?string
    {
        $stack = $this->stack();
        if ($stack === null || $stack === self::caller()) {
            return null;
        }
        [$uid, $gid] = explode(':', self::caller());

        return "{$this->composeFile} runs the app as uid {$stack}, but the checkout's user is {$uid}:{$gid}: "
            ."set HOST_UID={$uid} and HOST_GID={$gid} in .env (`vendor/bin/kanban doctor --fix` does), then rebuild the main stack";
    }

    /** Writes the checkout's user into .env; the line it did, or null when nothing was needed. */
    public function fix(): ?string
    {
        if ($this->problem() === null) {
            return null;
        }
        $file = $this->main.'/.env';
        $text = (string) @file_get_contents($file);
        foreach (array_combine(self::KEYS, explode(':', self::caller())) as $key => $id) {
            $text = preg_match("/^{$key}=.*$/m", $text)
                ? (string) preg_replace("/^{$key}=.*$/m", "{$key}={$id}", $text)
                : rtrim($text, "\n").($text === '' ? '' : "\n")."{$key}={$id}\n";
        }
        file_put_contents($file, $text);

        return 'set HOST_UID and HOST_GID to '.self::caller().' in .env: rebuild the main stack';
    }
}
