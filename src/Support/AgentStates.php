<?php

namespace PetarSpasic\Kanban\Support;

/**
 * The state of the latest agent bound to each card, from the runtime agent files (the file's mtime is the heartbeat).
 */
final class AgentStates
{
    /** @return array<string, string> card id => `stopped`, `stale 25m`, or how long it has been bound (`4m`) */
    public static function byCard(Paths $paths, int $staleMinutes): array
    {
        $latest = [];
        foreach (glob($paths->agents().'/*.json') ?: [] as $file) {
            $agent = json_decode((string) @file_get_contents($file), true);
            $card = is_array($agent) ? ($agent['card'] ?? null) : null;
            $beat = (int) @filemtime($file);
            if (is_string($card) && (! isset($latest[$card]) || $beat > $latest[$card][1])) {
                $latest[$card] = [$agent, $beat];
            }
        }

        return array_map(function (array $entry) use ($staleMinutes) {
            [$agent, $beat] = $entry;
            $idle = time() - $beat;

            return match (true) {
                ! empty($agent['stopped_at']) => 'stopped',
                $idle > 60 * $staleMinutes => 'stale '.Clock::human($idle),
                default => Clock::human(isset($agent['bound_at']) ? Clock::seconds($agent['bound_at']) : $idle),
            };
        }, $latest);
    }

    public static function isWorking(string $state): bool
    {
        return $state !== 'stopped' && ! str_starts_with($state, 'stale');
    }
}
