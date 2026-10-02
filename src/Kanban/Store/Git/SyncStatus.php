<?php

namespace PetarSpasic\Kanban\Store\Git;

use PetarSpasic\Kanban\Store\Exceptions\Conflict;
use PetarSpasic\Kanban\Store\Exceptions\Invalid;
use PetarSpasic\Kanban\Store\Exceptions\KanbanException;
use PetarSpasic\Kanban\Store\Exceptions\RemoteFailed;
use PetarSpasic\Kanban\Support\Clock;
use PetarSpasic\Kanban\Support\Json;
use PetarSpasic\Kanban\Support\Paths;
use Throwable;

/**
 * What the last sync of this clone did. One small file in the runtime directory, written on every exit of a sync and
 * read by the UI, `status` and the brief. Advisory: nothing decides a claim, a write or a merge from it.
 */
final class SyncStatus
{
    private const FILE = 'sync.status.json';

    /** Failures that do not clear by retrying, so they are shown at once. */
    private const LASTING = ['invalid', 'conflict', 'rejected'];

    public function __construct(private readonly Paths $paths) {}

    /** @return array{state: string, kind: ?string, attempt_at: string, ok_at: ?string, error: ?string, ahead: int, behind: int, failures: int}|null */
    public function read(): ?array
    {
        $raw = @file_get_contents($this->paths->runtime(self::FILE));
        $data = $raw === false ? null : json_decode($raw, true);
        if (! is_array($data) || ! in_array($data['state'] ?? null, ['ok', 'failed'], true)) {
            return null;
        }
        $text = fn (string $key) => is_string($data[$key] ?? null) ? $data[$key] : null;

        return [
            'state' => $data['state'], 'kind' => $text('kind'), 'attempt_at' => (string) $text('attempt_at'), 'ok_at' => $text('ok_at'),
            'error' => $text('error'), 'ahead' => max(0, (int) ($data['ahead'] ?? 0)), 'behind' => max(0, (int) ($data['behind'] ?? 0)),
            'failures' => max(0, (int) ($data['failures'] ?? 0)),
        ];
    }

    public function recordOk(): void
    {
        $now = Clock::now();
        $this->write(['state' => 'ok', 'kind' => null, 'attempt_at' => $now, 'ok_at' => $now, 'error' => null, 'ahead' => 0, 'behind' => 0, 'failures' => 0]);
    }

    /** $ahead and $behind are as of the last fetch; a retry of the run that already failed keeps the count (the notice counts runs, not tries). */
    public function recordFailure(Throwable $e, int $ahead, int $behind, bool $retry = false): void
    {
        $before = $this->read();
        $this->write([
            'state' => 'failed', 'kind' => self::kind($e), 'attempt_at' => Clock::now(), 'ok_at' => $before['ok_at'] ?? null,
            'error' => self::describe($e), 'ahead' => $ahead, 'behind' => $behind, 'failures' => ($before['state'] ?? '') === 'failed' ? $before['failures'] + ($retry ? 0 : 1) : 1,
        ]);
    }

    /** The board notice, or null while there is nothing to worry about: a lone transient failure is a blip. */
    public function notice(): ?string
    {
        $s = $this->read();
        if ($s === null || $s['state'] !== 'failed' || ($s['failures'] < 2 && ! in_array($s['kind'], self::LASTING, true))) {
            return null;
        }
        if ($s['kind'] === 'invalid') {
            return 'Sync stopped after a pull: '.$s['error'].'. Run vendor/bin/kanban validate; break a dependency cycle with kanban set, then sync again.';
        }
        $head = $s['ahead'] > 0 ? 'Not pushed: '.$s['ahead'].' commit'.($s['ahead'] === 1 ? '' : 's') : 'Not synced';

        return $head.' ('.$s['error'].').'.($s['behind'] > 0 ? ' Behind by '.$s['behind'].'.' : '').' The next sync retries.';
    }

    /** One line for `status` and the brief while the last sync failed, whatever the number of failures. */
    public function line(): ?string
    {
        $s = $this->read();
        if ($s === null || $s['state'] !== 'failed') {
            return null;
        }

        return 'last sync failed ('.$s['failures'].' in a row): '.$s['error'].'; '.$s['ahead'].' unpushed'.($s['behind'] > 0 ? ', behind by '.$s['behind'] : '');
    }

    /** Changes with what the notice says and not with the time: an idle healthy board and a dead remote retried all day stay on 304. */
    public function digest(): string
    {
        $s = $this->read();
        if ($s === null || $s['state'] !== 'failed') {
            return 'ok';
        }

        return sha1(implode('|', [$s['kind'], $s['error'], $s['ahead'], $s['behind'], min($s['failures'], 2)]));
    }

    private static function kind(Throwable $e): string
    {
        return match (true) {
            $e instanceof Invalid => 'invalid',
            $e instanceof Conflict => 'conflict',
            $e instanceof RemoteFailed => str_contains($e->getMessage(), 'kept being rejected') ? 'rejected' : 'remote',
            default => 'error',
        };
    }

    private static function describe(Throwable $e): string
    {
        $text = $e->getMessage();
        if ($e instanceof KanbanException && $e->details !== []) {
            $text .= ': '.$e->details[0];
        }
        // credentials in a remote URL must not reach the page
        $text = (string) preg_replace('~(?<=://)[^/@\s]+@~', '', $text);

        return mb_substr(trim((string) preg_replace('/\s+/', ' ', $text)), 0, 300);
    }

    /** @param  array<string, mixed>  $data */
    private function write(array $data): void
    {
        try {
            Json::write($this->paths->runtime(self::FILE), json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
        } catch (Throwable) {
            // advisory: a sync never fails because its record could not be written
        }
    }
}
