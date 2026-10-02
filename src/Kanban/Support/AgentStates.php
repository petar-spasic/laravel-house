<?php

namespace PetarSpasic\Kanban\Support;

use Throwable;

/**
 * The state of the latest agent bound to each card, from the runtime agent files (the file's mtime is the heartbeat).
 */
final class AgentStates
{
    /**
     * The latest agent bound to each card.
     *
     * @return array<string, array{state: string, since: int, beat: int}> state is `working`, `stale` or `stopped`; since is when it was bound, beat its last heartbeat (epoch seconds)
     */
    public static function entries(Paths $paths, int $staleMinutes): array
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

            return [
                'state' => match (true) {
                    ! empty($agent['stopped_at']) => 'stopped',
                    self::isStale($beat, $staleMinutes) => 'stale',
                    default => 'working',
                },
                'since' => self::boundAt($agent) ?? $beat,
                'beat' => $beat,
            ];
        }, $latest);
    }

    /** @return array<string, string> card id => `stopped`, `stale 25m`, or how long it has been bound (`4m`) */
    public static function byCard(Paths $paths, int $staleMinutes): array
    {
        return array_map(fn (array $agent) => match ($agent['state']) {
            'stopped' => 'stopped',
            'stale' => 'stale '.Clock::human(max(0, time() - $agent['beat'])),
            default => Clock::human(max(0, time() - $agent['since'])),
        }, self::entries($paths, $staleMinutes));
    }

    public static function isStale(int $beat, int $staleMinutes): bool
    {
        return time() - $beat > 60 * $staleMinutes;
    }

    /**
     * Changes when an agent is bound, stops or goes stale, and not on a heartbeat: binding and stopping rewrite the
     * agent file (its size changes), a heartbeat only touches it.
     */
    public static function signature(Paths $paths, int $staleMinutes): string
    {
        $parts = [];
        foreach (glob($paths->agents().'/*.json') ?: [] as $file) {
            $stat = @stat($file);
            $parts[] = basename($file).':'.($stat ? $stat['size'].':'.(self::isStale($stat['mtime'], $staleMinutes) ? 's' : 'w') : '-');
        }

        return implode(',', $parts);
    }

    /** @param  array<string, mixed>  $agent */
    private static function boundAt(array $agent): ?int
    {
        try {
            return is_string($agent['bound_at'] ?? null) ? Clock::parse($agent['bound_at'])->getTimestamp() : null;
        } catch (Throwable) {
            return null;
        }
    }
}
