<?php

namespace PetarSpasic\Kanban\Store\Git;

/** Board writes that are on disk but not committed yet (git was unusable). Flushed by the next host write. */
final class Journal
{
    public function __construct(private readonly string $file) {}

    /** @param  array{at: string, by: string, message: string, paths: list<string>}  $entry */
    public function append(array $entry): void
    {
        if (! is_dir(dirname($this->file))) {
            @mkdir(dirname($this->file), 0775, true);
        }
        file_put_contents($this->file, json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n", FILE_APPEND | LOCK_EX);
    }

    /** @return list<array{at: string, by: string, message: string, paths: list<string>}> */
    public function entries(): array
    {
        if (! is_file($this->file)) {
            return [];
        }
        $lines = array_filter(explode("\n", (string) file_get_contents($this->file)), fn (string $line) => trim($line) !== '');

        return array_values(array_filter(array_map(fn (string $line) => json_decode($line, true), $lines), 'is_array'));
    }

    public function count(): int
    {
        return count($this->entries());
    }

    public function clear(): void
    {
        if (is_file($this->file)) {
            unlink($this->file);
        }
    }
}
