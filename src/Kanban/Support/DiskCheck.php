<?php

namespace PetarSpasic\LaravelHouse\Kanban\Support;

use Symfony\Component\Process\Process;

/**
 * Free space and free inodes below a share of the filesystem. A tmpfs runs out of inodes long before space, and then
 * every write fails with "No space left on device" while df -h shows room.
 */
final class DiskCheck
{
    /**
     * @param  array<string, string>  $paths  label => path
     * @return list<string> e.g. "tmp (/tmp) inodes 4% free"
     */
    public static function low(array $paths, float $minFree = 0.10): array
    {
        $low = [];
        foreach ($paths as $label => $path) {
            if (! is_dir($path)) {
                continue;
            }
            foreach (['space' => [], 'inodes' => ['-i']] as $what => $flags) {
                $free = self::freeShare($path, $flags);
                if ($free !== null && $free < $minFree) {
                    $low[] = sprintf('%s (%s) %s %d%% free', $label, $path, $what, (int) floor($free * 100));
                }
            }
        }

        return $low;
    }

    /** Free share of the filesystem holding $path, or null when df cannot tell (no df, or no inode count). */
    private static function freeShare(string $path, array $flags): ?float
    {
        $df = new Process(['df', '-P', ...$flags, $path]);
        $df->run();
        $lines = preg_split('/\R/', trim($df->getOutput()));
        if (! $df->isSuccessful() || count($lines) < 2) {
            return null;
        }
        $cols = preg_split('/\s+/', trim((string) end($lines)));
        [$total, $available] = [(float) ($cols[1] ?? 0), (float) ($cols[3] ?? 0)];

        return $total > 0 ? $available / $total : null;
    }
}
