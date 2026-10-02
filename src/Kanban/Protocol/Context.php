<?php

namespace PetarSpasic\LaravelHouse\Kanban\Protocol;

use PetarSpasic\LaravelHouse\Kanban\Store\Card;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\PolicyRefused;
use PetarSpasic\LaravelHouse\Kanban\Store\Snapshot;
use PetarSpasic\LaravelHouse\Kanban\Support\Git;
use PetarSpasic\LaravelHouse\Kanban\Support\Paths;

/** What an agent needs about its card: `kanban context` and the SessionStart context of a card worktree. */
final class Context
{
    /** Claude Code keeps only a 2 KB preview of hook output over 10,000 characters: the gates and protocol lines come after the body. */
    private const BODY_LIMIT = 6000;

    /** @param  array<string, mixed>  $config  the `kanban` config */
    public function __construct(
        private readonly Paths $paths,
        private readonly array $config,
    ) {}

    /** The card whose worktree contains $dir (a code worktree under `.claude/worktrees/`), or null. */
    public function cardAt(Snapshot $snapshot, string $dir): ?Card
    {
        $dir = realpath($dir) ?: $dir;
        foreach ($snapshot->cards(fn (Card $c) => in_array($c->stage(), ['doing', 'review'], true)) as $card) {
            $worktree = $this->worktree($card);
            if ($worktree !== null && ($dir === $worktree || str_starts_with($dir, $worktree.'/'))) {
                return $card;
            }
        }

        return null;
    }

    public function worktree(Card $card): ?string
    {
        $relative = $card->work()['worktree'] ?? null;
        if (! is_string($relative) || $relative === '') {
            return null;
        }
        $path = str_starts_with($relative, '/') ? $relative : $this->paths->main.'/'.$relative;

        return realpath($path) ?: $path;
    }

    /** The card's worktree when $cwd is inside it; refused otherwise (agents act only on their own card). */
    public function requireInside(Card $card, string $cwd, string $what): string
    {
        $worktree = $this->worktree($card);
        $cwd = realpath($cwd) ?: $cwd;
        if ($worktree === null || ($cwd !== $worktree && ! str_starts_with($cwd, $worktree.'/'))) {
            throw new PolicyRefused("{$what} runs from {$card->id()}'s worktree".($worktree === null ? ' (it has none)' : ": cd {$worktree}"));
        }

        return $worktree;
    }

    /** @return list<string> */
    public function lines(Card $card, Snapshot $snapshot, bool $evaluate = false): array
    {
        $work = $card->work() ?? [];
        $worktree = $this->worktree($card);
        $git = $worktree !== null && is_dir($worktree) ? new Git($worktree) : null;
        $base = is_string($work['base'] ?? null) ? $work['base'] : null;

        $lines = ["{$card->id()} {$card->stage()} {$card->priority()} {$card->type()} {$card->board} {$card->title()}"];
        if ($card->blocked() !== null) {
            $lines[] = 'blocked: '.$card->blocked();
        }
        if ($worktree !== null) {
            $lines[] = "worktree {$worktree}".(isset($work['branch']) ? " branch {$work['branch']}" : '').($base !== null ? ' base '.substr($base, 0, 7) : '');
        }
        $stack = $work['stack'] ?? null;
        if (is_array($stack)) {
            $ports = (array) ($stack['ports'] ?? []);
            $lines[] = 'stack '.($stack['url'] ?? '-').($ports === [] ? '' : ' ports '.implode(' ', array_map(fn ($k, $v) => "{$k}={$v}", array_keys($ports), $ports)));
        }
        $lines[] = 'acceptance:';
        foreach ($card->acceptance() as $criterion) {
            $lines[] = '  ['.($criterion['done'] ? 'x' : ' ')."] {$criterion['id']}. {$criterion['text']}";
        }
        if ($card->dependsOn() !== []) {
            $lines[] = 'deps: '.implode(', ', array_map(fn (string $id) => $id.' '.($snapshot->card($id)?->stage() ?? 'missing'), $card->dependsOn()));
        }
        $notes = $this->notes($card, (string) ($work['started'] ?? ''));
        if ($notes !== []) {
            $lines[] = 'notes from the owner and main:';
            foreach ($notes as $note) {
                $lines[] = '  '.$note;
            }
        }
        if (($verdict = $this->last($card, 'verdict')) !== null) {
            $lines[] = 'last verdict: '.$verdict['decision'].' '.substr((string) ($verdict['at'] ?? ''), 0, 16)
                .(isset($verdict['head']) ? ' @'.substr($verdict['head'], 0, 7) : '');
            foreach (array_merge($verdict['failed'] ?? [], array_map(fn ($i) => 'issue: '.$i, $verdict['issues'] ?? [])) as $item) {
                $lines[] = '  '.$item;
            }
            if (isset($verdict['note'])) {
                $lines[] = '  note: '.$verdict['note'];
            }
        }
        $main = 'refs/heads/'.($this->config['main_branch'] ?? 'main');
        if ($git !== null) {
            $commits = array_values(array_filter(explode("\n", $git->attempt(['log', '--no-merges', '--format=%h %s', '-n', '20', $main.'..HEAD'])->out)));
            $total = (int) $git->line(['rev-list', '--count', '--no-merges', $main.'..HEAD']);
            $lines[] = 'commits not on main: '.$total.($total > count($commits) ? ' (newest '.count($commits).' shown)' : '');
            foreach ($commits as $commit) {
                $lines[] = '  '.$commit;
            }
            $status = array_values(array_filter(explode("\n", rtrim($git->attempt(['status', '--porcelain', '--untracked-files=all'])->out))));
            $conflicted = array_values(array_filter($status, fn (string $l) => preg_match('/^(UU|AA|DD|AU|UA|DU|UD) /', $l) === 1));
            $dirty = array_values(array_diff($status, $conflicted));
            $lines[] = 'dirty: '.($dirty === [] ? 'none' : implode(', ', array_map(fn ($l) => trim(substr($l, 3)), array_slice($dirty, 0, 20))));
            if ($conflicted !== []) {
                $lines[] = 'conflicted: '.implode(', ', array_map(fn ($l) => substr($l, 3), $conflicted)).' (a merge of main is in progress: resolve, git add, git commit)';
            }
        }
        if (trim((string) ($card->data['body'] ?? '')) !== '') {
            $body = rtrim((string) $card->data['body']);
            $lines[] = 'body:';
            foreach (explode("\n", mb_substr($body, 0, self::BODY_LIMIT)) as $line) {
                $lines[] = '  '.$line;
            }
            if (mb_strlen($body) > self::BODY_LIMIT) {
                $lines[] = "  … cut here; the whole body: `vendor/bin/kanban show {$card->id()}`";
            }
        }

        if ($evaluate) {
            $report = $this->last($card, 'report');
            if ($report !== null) {
                $lines[] = 'worker report: '.$report['status'].' '.substr((string) ($report['at'] ?? ''), 0, 16)
                    .(($report['ticks'] ?? []) === [] ? '' : ' ticks '.implode(',', $report['ticks']));
                foreach (explode("\n", (string) ($report['summary'] ?? '')) as $line) {
                    $lines[] = '  '.$line;
                }
                foreach ($report['verified'] ?? [] as $verified) {
                    $lines[] = '  verified: '.$verified;
                }
            }
            if ($git !== null) {
                $lines[] = 'this card\'s changes, diff --stat main...HEAD:';
                foreach (array_filter(explode("\n", rtrim($git->attempt(['diff', '--stat', $main.'...HEAD'])->out))) as $line) {
                    $lines[] = '  '.trim($line);
                }
            }
        }
        $gates = (new Gates($this->config))->commands();
        $lines[] = 'gates:'.($gates === [] ? ' none' : '');
        foreach ($gates as $gate) {
            $lines[] = '  '.$gate;
        }
        if ($evaluate) {
            $criteria = implode(' ', array_map(fn (array $c) => "--check={$c['id']}:pass|fail:\"evidence\"", $card->acceptance()));
            $lines[] = "protocol: read-only; verify each criterion, then `vendor/bin/kanban verdict {$card->id()} approve|reject {$criteria} [--issue=\"…\"] [--discovered=\"bug: Title — body\"]`";
        } else {
            $lines[] = "protocol: work and commit only in this worktree; when done `vendor/bin/kanban report {$card->id()} --status=review --tick=N --summary-file=- <<'EOF' … EOF` (or --status=blocked --reason=\"…\")";
        }

        return $lines;
    }

    /**
     * A log entry's actor: the role, and the person in brackets when the entry names one. Cleaned here again, because
     * entries arrive from any clone and end up in an agent's prompt.
     *
     * @param  array<string, mixed>  $entry
     */
    public static function actor(array $entry): string
    {
        $who = mb_substr(trim((string) preg_replace('/[\x00-\x1F\x7F\s]+/u', ' ', (string) ($entry['who'] ?? ''))), 0, 80);

        return (string) ($entry['by'] ?? '?').($who === '' ? '' : " ({$who})");
    }

    /**
     * Notes and stage-change reasons the owner and main left since the card was started.
     *
     * @return list<string>
     */
    private function notes(Card $card, string $since): array
    {
        $notes = [];
        foreach ($card->log() as $entry) {
            if (($entry['at'] ?? '') < $since || ! in_array($entry['by'] ?? null, ['owner', 'main'], true)) {
                continue;
            }
            $text = match ($entry['event'] ?? null) {
                'note' => $entry['text'] ?? null,
                'stage' => isset($entry['reason']) ? "{$entry['from']}→{$entry['to']}: {$entry['reason']}" : null,
                default => null,
            };
            if (is_string($text) && $text !== '') {
                $notes[] = substr((string) $entry['at'], 0, 16).' '.self::actor($entry).": {$text}";
            }
        }

        return $notes;
    }

    /**
     * Latest `report` or `verdict` log entry of the card.
     *
     * @return array<string, mixed>|null
     */
    private function last(Card $card, string $event): ?array
    {
        foreach (array_reverse($card->log()) as $entry) {
            if (($entry['event'] ?? null) === $event) {
                return $entry;
            }
        }

        return null;
    }
}
