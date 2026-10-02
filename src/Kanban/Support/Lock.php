<?php

namespace PetarSpasic\Kanban\Support;

use PetarSpasic\Kanban\Store\Exceptions\LockTimeout;
use RuntimeException;

final class Lock
{
    /** @param  resource|null  $handle */
    private function __construct(private $handle) {}

    public static function exclusive(string $path, float $timeout = 10.0): self
    {
        return self::acquire($path, LOCK_EX, $timeout);
    }

    public static function shared(string $path, float $timeout = 2.0): self
    {
        return self::acquire($path, LOCK_SH, $timeout);
    }

    /** Non-blocking exclusive lock, or null when someone else holds it. */
    public static function try(string $path): ?self
    {
        $handle = self::open($path);
        if (flock($handle, LOCK_EX | LOCK_NB)) {
            return new self($handle);
        }
        fclose($handle);

        return null;
    }

    public function release(): void
    {
        if ($this->handle !== null) {
            flock($this->handle, LOCK_UN);
            fclose($this->handle);
            $this->handle = null;
        }
    }

    public function __destruct()
    {
        $this->release();
    }

    private static function acquire(string $path, int $mode, float $timeout): self
    {
        $handle = self::open($path);
        $deadline = microtime(true) + $timeout;
        while (! flock($handle, $mode | LOCK_NB)) {
            if (microtime(true) >= $deadline) {
                fclose($handle);
                throw new LockTimeout("lock busy after {$timeout}s: {$path}");
            }
            usleep(random_int(5000, 20000));
        }

        return new self($handle);
    }

    /** @return resource */
    private static function open(string $path)
    {
        $dir = dirname($path);
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $handle = @fopen($path, 'c');
        if ($handle === false) {
            throw new RuntimeException("cannot open lock {$path}");
        }

        return $handle;
    }
}
