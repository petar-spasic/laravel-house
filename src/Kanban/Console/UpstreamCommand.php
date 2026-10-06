<?php

namespace PetarSpasic\LaravelHouse\Kanban\Console;

use Composer\InstalledVersions;
use PetarSpasic\LaravelHouse\Kanban\Store\Card;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\Invalid;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\NotFound;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\PolicyRefused;
use PetarSpasic\LaravelHouse\Kanban\Upstream\Findings;
use PetarSpasic\LaravelHouse\Kanban\Upstream\Gh;
use PetarSpasic\LaravelHouse\Kanban\Upstream\Scrubber;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

#[AsCommand(name: 'kanban:upstream')]
class UpstreamCommand extends Command
{
    public const LABEL = 'agent-finding';

    protected $signature = 'kanban:upstream
        {action? : file|new|dismiss (none: list the pending findings)}
        {finding? : ID:logid, as the list prints it; for new, "Title — body"}
        {--new : File it although open issues match}
        {--comment= : Add it to this open issue instead}
        {--reason= : Why it is dismissed}';

    protected $description = 'Findings about the house package that agents flagged: list them, file one as an issue (gh), dismiss one, or file your own (new)';

    protected function perform(): int
    {
        $this->requireMainOrOwner('upstream');

        return match ($this->argument('action')) {
            null => $this->list(),
            'file' => $this->file(),
            'new' => $this->own(),
            'dismiss' => $this->dismiss(),
            default => throw new Invalid("upstream takes file, new or dismiss, not '{$this->argument('action')}'"),
        };
    }

    private function list(): int
    {
        $pending = Findings::pending($this->store()->snapshot());
        foreach ($pending as ['card' => $card, 'entry' => $entry]) {
            $this->say("{$card->id()}:{$entry['id']} {$entry['title']}");
            foreach (array_filter(explode("\n", (string) ($entry['body'] ?? '')), fn (string $l) => trim($l) !== '') as $line) {
                $this->say('  '.$line);
            }
        }
        if ($pending === []) {
            $this->say('no pending upstream findings');
        } elseif (! Findings::enabled($this->config())) {
            $this->say('filing is off (KANBAN_UPSTREAM): list them for the owner, or `kanban upstream dismiss ID:logid --reason=…`');
        }

        return self::SUCCESS;
    }

    private function file(): int
    {
        $this->requireEnabled();
        [$card, $entry] = $this->finding();
        [$issue, $url] = $this->send((string) $entry['title'], (string) ($entry['body'] ?? ''), 'an agent');

        return $this->filed($card, $entry, $issue, $url);
    }

    /** The main session's (or the owner's) own finding: no card holds it, so nothing is recorded. */
    private function own(): int
    {
        $this->requireEnabled();
        [['title' => $title, 'body' => $body]] = Findings::stage([(string) $this->argument('finding')], $this->scrubber(), 'the finding');
        [$issue, $url] = $this->send($title, $body, $this->actor()->isMain() ? 'the main session' : 'the owner');
        $this->say('filed '.($issue === null ? '' : "#{$issue} ").$url);

        return self::SUCCESS;
    }

    private function requireEnabled(): void
    {
        if (! Findings::enabled($this->config())) {
            throw new PolicyRefused('filing upstream is off: KANBAN_UPSTREAM=true turns it on');
        }
    }

    private function scrubber(): Scrubber
    {
        return Scrubber::forProject($this->paths(), $this->store()->snapshot()->key(), (string) $this->setting('remote', 'origin'));
    }

    /**
     * Scrubs, searches open issues (unless --new), then comments on --comment or files a new labelled issue.
     *
     * @return array{0: ?int, 1: string} the issue number and the URL gh printed
     */
    private function send(string $title, string $body, string $by): array
    {
        $comment = $this->option('comment');
        if ($comment !== null && ! ctype_digit((string) $comment)) {
            throw new Invalid('--comment takes an issue number');
        }
        if ($comment !== null && $this->option('new')) {
            throw new Invalid('--new files a new issue and --comment adds to one: give one of them');
        }
        $query = Findings::query($title);
        $scrubber = $this->scrubber();
        $scrubber->check($title."\n".$body, 'the finding');
        $scrubber->check($query, 'the search query');
        $gh = new Gh($this->paths()->main);
        if (($problem = $gh->unusable(Findings::host($this->config()))) !== null) {
            throw new PolicyRefused($problem);
        }
        $repo = Findings::repo($this->config());
        $footer = "---\nFlagged by {$by}; laravel-house ".self::version();
        $text = $body === '' ? $footer : "{$body}\n\n{$footer}";

        if ($comment !== null) {
            return [(int) $comment, $this->gh($gh, ['issue', 'comment', (string) $comment, '--repo', $repo, '--body', "**{$title}**\n\n{$text}"])];
        }
        if (! $this->option('new') && $query !== '') {
            $hits = json_decode($this->gh($gh, ['issue', 'list', '--repo', $repo, '--state', 'open', '--search', $query, '--json', 'number,title,url', '--limit', '5']), true);
            if (is_array($hits) && $hits !== []) {
                foreach ($hits as $hit) {
                    $this->say("open #{$hit['number']} {$hit['title']} {$hit['url']}");
                }
                throw new PolicyRefused('open issues match: --comment=N adds the finding to one, --new files it anyway');
            }
        }
        $url = $this->gh($gh, ['issue', 'create', '--repo', $repo, '--title', $title, '--body', $text, '--label', self::LABEL]);

        return [preg_match('#/issues/(\d+)#', $url, $m) === 1 ? (int) $m[1] : null, $url];
    }

    private function dismiss(): int
    {
        $reason = trim((string) $this->option('reason'));
        if ($reason === '' || mb_strlen($reason) > 300) {
            throw new Invalid('dismiss needs --reason (at most 300 characters)');
        }
        [$card, $entry] = $this->finding();
        $this->store()->update($card->id(), function (array $data) use ($entry, $reason) {
            $data['log'][] = ['event' => 'upstream_dismissed', 'finding' => $entry['id'], 'reason' => $reason];

            return $data;
        }, $this->actor());
        $this->say("dismissed {$card->id()}:{$entry['id']}");
        $this->reportPending();

        return self::SUCCESS;
    }

    /**
     * The pending finding the `finding` argument names.
     *
     * @return array{0: Card, 1: array<string, mixed>}
     */
    private function finding(): array
    {
        $ref = (string) $this->argument('finding');
        if (preg_match('/^(\S+):([0-9A-Za-z]{8})$/', $ref, $m) !== 1) {
            throw new Invalid('name the finding as ID:logid, as `kanban upstream` lists it');
        }
        $card = $this->store()->card($m[1]);
        $id = strtoupper($m[2]);
        foreach ($card->log() as $entry) {
            if (($entry['id'] ?? null) === $id && ($entry['event'] ?? null) === 'upstream') {
                if (isset(Findings::settled($card)[$id])) {
                    throw new PolicyRefused("{$card->id()}:{$id} is already filed or dismissed");
                }

                return [$card, $entry];
            }
        }
        throw new NotFound("{$card->id()} has no upstream finding {$id}");
    }

    /** @param  array<string, mixed>  $entry */
    private function filed(Card $card, array $entry, ?int $issue, string $url): int
    {
        $this->store()->update($card->id(), function (array $data) use ($entry, $issue, $url) {
            $data['log'][] = array_filter(['event' => 'upstream_filed', 'finding' => $entry['id'], 'issue' => $issue, 'url' => $url], fn ($v) => $v !== null && $v !== '');

            return $data;
        }, $this->actor());
        $this->say('filed '.($issue === null ? '' : "#{$issue} ").$url);
        $this->reportPending();

        return self::SUCCESS;
    }

    /** @param  list<string>  $args */
    private function gh(Gh $gh, array $args): string
    {
        [$exit, $out, $err] = $gh->run($args);
        if ($exit !== 0) {
            throw new PolicyRefused("gh {$args[0]} {$args[1]} failed: ".trim($err ?: $out));
        }

        return trim($out);
    }

    private static function version(): string
    {
        try {
            $version = (string) InstalledVersions::getPrettyVersion('petar-spasic/laravel-house');
            $reference = (string) InstalledVersions::getReference('petar-spasic/laravel-house');

            return $version.($reference === '' || str_contains($version, $reference) ? '' : ' ('.substr($reference, 0, 7).')');
        } catch (Throwable) {
            return 'unknown';
        }
    }
}
