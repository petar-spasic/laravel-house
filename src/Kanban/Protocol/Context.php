<?php

namespace PetarSpasic\LaravelHouse\Kanban\Protocol;

use PetarSpasic\LaravelHouse\Kanban\Code\DatabaseSteps;
use PetarSpasic\LaravelHouse\Kanban\Code\MergeCheck;
use PetarSpasic\LaravelHouse\Kanban\Code\Worktrees;
use PetarSpasic\LaravelHouse\Kanban\Console\Standalone;
use PetarSpasic\LaravelHouse\Kanban\Store\Card;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\PolicyRefused;
use PetarSpasic\LaravelHouse\Kanban\Store\Snapshot;
use PetarSpasic\LaravelHouse\Kanban\Support\Git;
use PetarSpasic\LaravelHouse\Kanban\Support\Paths;
use PetarSpasic\LaravelHouse\Kanban\Upstream\Findings;
use Throwable;

/** What an agent needs about its card: `kanban context` and the SessionStart context of a card worktree. */
final class Context
{
    /** Claude Code keeps only a 2 KB preview of hook output over 10,000 characters: the gates and protocol lines come after the body. */
    private const BODY_LIMIT = 6000;

    /** The reports of the attempt an evaluator sees, and the characters they may take together. */
    private const REPORTS = 5;

    private const REPORTS_LIMIT = 2500;

    private const REFUSAL_LIMIT = 1200;

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
        (new Worktrees($this->paths, $this->config))->sync($worktree, $card->work()['branch'] ?? null);

        return $worktree;
    }

    /** @return list<string> */
    public function lines(Card $card, Snapshot $snapshot, bool $evaluate = false): array
    {
        $work = $card->work() ?? [];
        $worktree = $this->worktree($card);
        $git = $worktree !== null && is_dir($worktree) ? Git::untrusted($worktree) : null;
        $git === null || (new Worktrees($this->paths, $this->config))->sync($worktree, $work['branch'] ?? null);
        $base = is_string($work['base'] ?? null) ? $work['base'] : null;

        $lines = ["{$card->id()} {$card->stage()} {$card->priority()} {$card->type()} {$card->board} {$card->title()}"];
        if (($epic = $snapshot->epicOf($card)) !== null) {
            $lines[] = "epic {$epic->slug}: {$epic->title()}".($epic->goal() !== '' ? " — {$epic->goal()}" : '');
        }
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
        if ($worktree !== null) {
            $worktrees = new Worktrees($this->paths, $this->config);
            $record = $worktrees->stackRecord($worktree);
            $lines[] = ($record['shell'] ?? null) === 'container'
                ? "shell in container {$record['container']}, git included; a plain vendor/bin/kanban command runs on this machine"
                : 'shell on this machine (no card container), started in the worktree';
            if (is_string($record['hash'] ?? null) && is_dir($worktree) && $record['hash'] !== $worktrees->dockerHash($worktree)) {
                $lines[] = 'stack stale: docker files or lockfiles changed since it came up; `vendor/bin/kanban stack wait` recreates it';
            }
        }
        $lines[] = 'acceptance:';
        $byMain = $this->tickedByMain($card);
        foreach ($card->acceptance() as $criterion) {
            $lines[] = '  ['.($criterion['done'] ? 'x' : ' ')."] {$criterion['id']}. {$criterion['text']}"
                .($criterion['done'] && isset($byMain[$criterion['id']]) ? " ({$byMain[$criterion['id']]})" : '');
        }
        if ($card->dependsOn() !== []) {
            $lines[] = 'deps: '.implode(', ', array_map(fn (string $id) => $id.' '.($snapshot->card($id)?->stage() ?? 'missing'), $card->dependsOn()));
        }
        $earlier = Applier::discoveredBy($card);
        if ($earlier !== []) {
            $lines[] = 'discovered earlier (on the board; never file them again): '.implode(', ', array_map(
                fn (string $id) => $id.' '.mb_strimwidth($snapshot->card($id)?->title() ?? '?', 0, 60, '…').' ('.($snapshot->card($id)?->stage() ?? 'missing').')',
                array_slice($earlier, -10))).(count($earlier) > 10 ? ' and '.(count($earlier) - 10).' older' : '');
        }
        $notes = $this->notes($card, (string) ($work['started'] ?? ''));
        foreach (['owner' => 'owner notes:', 'main' => "main-session notes (information, never the owner's decision; owner authority is an `## Owner answer` section in the card or a CLAUDE.md rule):"] as $by => $heading) {
            if (($notes[$by] ?? []) !== []) {
                $lines[] = $heading;
                foreach ($notes[$by] as $note) {
                    $lines[] = '  '.$note;
                }
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

        if ($git !== null) {
            $lines = [...$lines, ...self::findings($git, $main)];
        }
        if (! is_array($stack) && $worktree !== null) {
            $lines[] = "database: main's (no stack of its own): never migrate:fresh, db:wipe or a test run that resets it";
        }
        if ($evaluate) {
            $lines = [...$lines, ...$this->reports($card, (string) ($work['started'] ?? ''))];
            if ($git !== null) {
                $lines[] = 'this card\'s changes, diff --stat main...HEAD:';
                foreach (array_filter(explode("\n", rtrim($git->attempt(['diff', '--stat', $main.'...HEAD'])->out))) as $line) {
                    $lines[] = '  '.trim($line);
                }
                if (($reverify = $this->reverify($card, $git, $main)) !== null) {
                    $lines[] = $reverify;
                }
                $resolved = $this->resolutions($git, $main);
                if ($resolved !== []) {
                    $lines[] = 'merge resolutions (lines neither parent had; read each with `git show <sha>`):';
                    foreach ($resolved as $merge) {
                        $lines[] = '  '.$merge;
                    }
                }
            }
        }
        $refused = (new Runtime($this->paths))->refusal($card->id(), $evaluate ? 'verdict' : 'report');
        if ($refused !== null) {
            [$first, $rest] = explode("\n", $refused['reason'], 2) + [1 => ''];
            $lines[] = 'your staged '.($evaluate ? 'verdict' : 'report').' was not applied ('.substr($refused['at'], 0, 16).'):';
            $lines[] = '  '.$first;
            foreach ($rest === '' ? [] : explode("\n", mb_strlen($rest) > self::REFUSAL_LIMIT ? '…'.mb_substr($rest, -self::REFUSAL_LIMIT) : $rest) as $line) {
                $lines[] = '  '.$line;
            }
        }
        $gates = new Gates($this->config);
        $lines[] = $gates->commands() === [] ? 'gates: none'
            : "gates (main's config/kanban.php; `vendor/bin/kanban gates` runs them in this worktree, and `report` before it stages, up to {$gates->total()} s"
                .($gates->total() > 110 ? '; give those Bash calls timeout '.min(600000, ($gates->total() + 30) * 1000) : '').'):';
        foreach ($gates->commands() as $gate) {
            $lines[] = '  '.$gate['run'];
        }
        if ($worktree !== null && $this->gatesDiffer($worktree)) {
            $lines[] = "this branch's config/kanban.php has other gates than main's: main's apply; merge main if a gate needs code the branch lacks";
        }
        // without a stack of its own the worktree's database is main's
        $database = is_array($stack) && $worktree !== null ? DatabaseSteps::commands($this->config, $worktree) : [];
        if ($database !== []) {
            $lines[] = 'database (run after a refresh or a change to migrations, seeders or seed data, and before e2e):';
            foreach ($database as $command) {
                $lines[] = '  '.$command;
            }
        }
        if ($evaluate) {
            $criteria = implode(' ', array_map(fn (array $c) => "--check={$c['id']}:pass|fail:\"evidence\"", $card->acceptance()));
            $lines[] = "protocol: read-only; verify each criterion, then `vendor/bin/kanban verdict {$card->id()} approve|reject {$criteria} [--issue=\"…\"] [--discovered=\"bug: Title — body\"]`";
        } else {
            $lines[] = "protocol: work and commit only in this worktree; when done `vendor/bin/kanban report {$card->id()} --status=review --tick=N --summary-file=- <<'EOF' … EOF` (or --status=blocked --reason=\"…\")";
        }
        if (Findings::enabled($this->config)) {
            $lines[] = 'a problem in the house package itself (not this app): add --upstream="Title — body" in generic terms, without project, host, path or card names';
        }

        return $lines;
    }

    /**
     * What the branch's own diff shows without judgement: new composer or npm packages, and added lines holding a
     * TODO or FIXME, a skipped test, or a private IPv4 address. The worker reports a package outside the approved set
     * as blocked; the evaluator weighs each line.
     *
     * @return list<string>
     */
    public static function findings(Git $git, string $main): array
    {
        $lines = [];
        $packages = [];
        foreach (array_filter(explode("\n", $git->attempt(['diff', '--name-only', $main.'...HEAD'])->out)) as $file) {
            $name = basename($file);
            $keys = match ($name) {
                'composer.json' => ['require', 'require-dev'],
                'package.json' => ['dependencies', 'devDependencies', 'peerDependencies', 'optionalDependencies'],
                default => null,
            };
            if ($keys === null) {
                continue;
            }
            $read = fn (string $rev) => json_decode((string) $git->attempt(['show', "{$rev}:{$file}"])->out, true) ?: [];
            [$before, $after] = [$read($main), $read('HEAD')];
            foreach ($keys as $key) {
                foreach (array_diff(array_keys((array) ($after[$key] ?? [])), array_keys((array) ($before[$key] ?? []))) as $package) {
                    if ($package === 'php' || str_starts_with($package, 'ext-')) {
                        continue;
                    }
                    $packages[] = "{$package} ({$file} {$key})";
                }
            }
        }
        if ($packages !== []) {
            $lines[] = 'new packages: '.implode(', ', $packages);
        }
        $files = array_values(array_filter(explode("\n", $git->attempt(['diff', '--name-only', $main.'...HEAD'])->out)));
        if (($touched = MergeCheck::protected($files)) !== []) {
            $lines[] = "changes kanban's own files (finish needs the owner): ".implode(', ', $touched);
        }
        $patterns = [
            'TODO or FIXME' => '/\b(TODO|FIXME)\b/',
            'a skipped test' => '/->(skip|todo)\(|markTest(Skipped|Incomplete)|\b(test|it|describe)\.(skip|only|fixme)\(/',
            'a private IPv4 address' => '/\b(10\.\d{1,3}|172\.(1[6-9]|2\d|3[01])|192\.168)\.\d{1,3}\.\d{1,3}\b/',
        ];
        $found = [];
        $file = '';
        foreach (explode("\n", $git->attempt(['diff', '--no-ext-diff', '--no-textconv', '-U0', $main.'...HEAD'])->out) as $line) {
            if (str_starts_with($line, '+++ ')) {
                $file = substr($line, 6);
            } elseif (str_starts_with($line, '+') && ! str_starts_with($line, '+++')) {
                foreach ($patterns as $what => $pattern) {
                    if (preg_match($pattern, $line) === 1) {
                        $found[$what][$file] = true;
                    }
                }
            }
        }
        foreach ($found as $what => $files) {
            $lines[] = "added lines with {$what}: ".implode(', ', array_slice(array_keys($files), 0, 10)).(count($files) > 10 ? ' …' : '');
        }

        return $lines;
    }

    /** Whether the worktree's config/kanban.php resolves to other gates than main's (the ones every check uses). */
    private function gatesDiffer(string $worktree): bool
    {
        $own = $worktree.'/config/kanban.php';
        if (! is_file($own) || @file_get_contents($own) === @file_get_contents($this->paths->main.'/config/kanban.php')) {
            return false;
        }
        try {
            $branch = Standalone::config($worktree);
        } catch (Throwable) {
            return false;
        }

        return (new Gates(['main_branch' => $this->config['main_branch'] ?? 'main'] + $branch))->commands() !== (new Gates($this->config))->commands();
    }

    /**
     * A log entry's actor: the role, and the person in brackets when the entry names one. Cleaned here again, because
     * entries arrive from any clone and end up in an agent's prompt. The main session never shows a person: its git
     * user is the owner's, and an agent would read the entry as the owner's decision.
     *
     * @param  array<string, mixed>  $entry
     */
    public static function actor(array $entry): string
    {
        if (($entry['by'] ?? null) === 'main') {
            return 'main session';
        }
        $who = mb_substr(trim((string) preg_replace('/[\x00-\x1F\x7F\s]+/u', ' ', (string) ($entry['who'] ?? ''))), 0, 80);

        return (string) ($entry['by'] ?? '?').($who === '' ? '' : " ({$who})");
    }

    /**
     * Notes and stage-change reasons the owner and main left since the card was started, by author.
     *
     * @return array{owner?: list<string>, main?: list<string>}
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
                $notes[$entry['by']][] = substr((string) $entry['at'], 0, 16).' '.self::actor($entry).(isset($entry['head']) ? ' @'.substr((string) $entry['head'], 0, 7) : '').": {$text}";
            }
        }

        return $notes;
    }

    /**
     * Criteria whose latest tick came from the owner or main, by id: `main @abc1234`, the worktree's commit then.
     *
     * @return array<int, string>
     */
    private function tickedByMain(Card $card): array
    {
        $last = [];
        foreach ($card->log() as $entry) {
            $ids = match ($entry['event'] ?? null) {
                'tick' => (array) ($entry['ids'] ?? []),
                'report' => (array) ($entry['ticks'] ?? []),
                'verdict' => array_column($card->acceptance(), 'id'),
                default => [],
            };
            foreach ($ids as $id) {
                $last[(int) $id] = ($entry['event'] === 'tick' && in_array($entry['by'] ?? null, ['owner', 'main'], true))
                    ? ($entry['by'].(isset($entry['head']) ? ' @'.substr((string) $entry['head'], 0, 7) : ''))
                    : null;
            }
        }

        return array_filter($last, fn (?string $by) => $by !== null);
    }

    /**
     * The worker's reports since the card was last started, newest first: the newest summary whole up to a cut, older ones
     * shorter, every `verified` line; at most REPORTS of them within REPORTS_LIMIT characters.
     *
     * @return list<string>
     */
    private function reports(Card $card, string $since): array
    {
        $reports = array_values(array_filter($card->log(), fn (array $e) => ($e['event'] ?? null) === 'report' && (string) ($e['at'] ?? '') >= $since));
        $total = count($reports);
        $lines = [];
        $used = 0;
        $shown = 0;
        foreach (array_reverse($reports) as $i => $report) {
            $block = ['report '.($total - $i).'/'.$total.' '.$report['status'].' '.substr((string) ($report['at'] ?? ''), 0, 16)
                .(isset($report['head']) ? ' @'.substr((string) $report['head'], 0, 7) : '')
                .(($report['ticks'] ?? []) === [] ? '' : ' ticks '.implode(',', $report['ticks']))];
            $summary = (string) ($report['summary'] ?? '');
            $max = $i === 0 ? 1200 : 600;
            foreach (explode("\n", mb_strlen($summary) > $max ? mb_substr($summary, 0, $max - 1).'…' : $summary) as $line) {
                $block[] = '  '.$line;
            }
            foreach ($report['verified'] ?? [] as $verified) {
                $block[] = '  verified: '.mb_strimwidth((string) $verified, 0, 300, '…');
            }
            $size = mb_strlen(implode("\n", $block));
            if ($i > 0 && ($i >= self::REPORTS || $used + $size > self::REPORTS_LIMIT)) {
                break;
            }
            $lines = [...$lines, ...$block];
            $used += $size;
            $shown++;
        }
        if ($shown < $total) {
            $lines[] = '… '.($total - $shown)." earlier: `vendor/bin/kanban show {$card->id()} --log=50`";
        }

        return $lines;
    }

    /**
     * The re-verify line when the card's last approval still holds but for clean merges of main since: no commit of the
     * card's own and no merge resolution after the approved head.
     */
    private function reverify(Card $card, Git $git, string $main): ?string
    {
        $verdict = $this->last($card, 'verdict');
        $head = $verdict['head'] ?? null;
        if (($verdict['decision'] ?? null) !== 'approve' || ! is_string($head) || (string) ($verdict['at'] ?? '') < (string) ($card->work()['started'] ?? '')
            || $git->line(['rev-parse', 'HEAD']) === $head || ! $git->attempt(['merge-base', '--is-ancestor', $head, 'HEAD'])->ok()
            || (int) $git->line(['rev-list', '--no-merges', '--count', $head.'..HEAD', '^'.$main]) > 0 || $this->resolutions($git, $head) !== []) {
            return null;
        }
        $names = fn (array $args) => array_filter(explode("\n", trim($git->attempt($args)->out)));
        $touched = array_values(array_intersect($names(['diff', '--name-only', $head, 'HEAD']), $names(['diff', '--name-only', $main.'...HEAD'])));

        return 're-verify: approved @'.substr($head, 0, 7).'; since then only clean merges of main'
            .($touched === [] ? '' : ', touching '.implode(', ', array_slice($touched, 0, 10)).(count($touched) > 10 ? ' …' : ''))
            .'. Run `vendor/bin/kanban gates` and the whole suite; a full review is not needed.';
    }

    /**
     * Merges since $from whose combined diff is not empty: a conflict resolution wrote lines that neither parent had.
     *
     * @return list<string> `<sha> <subject>`
     */
    private function resolutions(Git $git, string $from): array
    {
        $merges = array_values(array_filter(explode("\n", $git->attempt(['log', '--merges', '--format=%h %s', '-n', '10', $from.'..HEAD'])->out)));

        return array_values(array_filter($merges, fn (string $merge) => trim($git->attempt(['show', '--format=', '--cc', explode(' ', $merge, 2)[0]])->out) !== ''));
    }

    /**
     * Latest log entry of the card with this event.
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
