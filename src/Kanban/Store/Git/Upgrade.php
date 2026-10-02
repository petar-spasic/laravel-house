<?php

namespace PetarSpasic\LaravelHouse\Kanban\Store\Git;

use Illuminate\Support\Str;
use PetarSpasic\LaravelHouse\Kanban\Import\DecisionsMarkdown;
use PetarSpasic\LaravelHouse\Kanban\Policy\Transitions;
use PetarSpasic\LaravelHouse\Kanban\Store\Board;
use PetarSpasic\LaravelHouse\Kanban\Store\BoardRef;
use PetarSpasic\LaravelHouse\Kanban\Store\Card;
use PetarSpasic\LaravelHouse\Kanban\Store\Epic;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\Invalid;
use PetarSpasic\LaravelHouse\Kanban\Store\Rev;
use PetarSpasic\LaravelHouse\Kanban\Store\Snapshot;
use PetarSpasic\LaravelHouse\Kanban\Store\Stage;

/**
 * The plan of `kanban fold-boards`: every board folded into one work board, and every decision card of a version 1
 * board either archived in `decisions.md` or turned into a backlog spike. A pure function of the board as loaded, the
 * archive and the target board; `GitStore::upgrade()` completes the log entries, validates and commits it. On a board
 * with nothing left to fold the plan is empty.
 *
 * - proposed, with open dependents: each dependent gets a `question:` block and an `## Open question (ID)` section, and
 *   a ready one goes back to backlog; archived, "asked on" them
 * - proposed, no open dependents: a backlog spike with the same id and a question block
 * - decided or superseded: archived; each open dependent gets an `## Owner decision (ID)` excerpt
 * - dropped: archived
 *
 * Links to an archived card are removed everywhere.
 */
final class Upgrade
{
    /** Characters of a decision copied into a dependent's body. */
    public const EXCERPT = 2000;

    private const BODY_MAX = 20000;

    private const BLOCKED_MAX = 500;

    private const OPEN = ['backlog', 'ready', 'doing', 'review'];

    /** @var array<string, array{data: array<string, mixed>, path: string, raw: bool}> id => the card after the fold; raw: moved as it is */
    public array $cards = [];

    /** @var list<string> board files deleted (archived cards, folded boards, emptied epics) */
    public array $removed = [];

    /** @var array<string, array<string, mixed>> kanban.json, board and epic files rewritten => their data */
    public array $files = [];

    /** The new bytes of the archive, or null when it stays as it is. */
    public ?string $archive = null;

    /** @var array<string, array{0: string, 1: string}> id => [board before, board after] */
    public array $moves = [];

    /** @var array<string, string> id => its stage and title */
    public array $archived = [];

    /** @var array<string, string> id => title of each decision card that becomes a spike */
    public array $spikes = [];

    /** @var array<string, array{title: string, cards: list<string>}> decision id => the question and the cards it lands on */
    public array $questions = [];

    /** @var array<string, string> card id => the block it keeps instead of a question */
    public array $kept = [];

    /** @var list<string> cards in doing or review: the fold waits for them */
    public array $active = [];

    /** @var list<string> open cards with no `area:*` label after the fold */
    public array $noArea = [];

    /** Distinct areas among the cards that could start after the fold (backlog or ready, unblocked, dependencies done). */
    public int $startableAreas = 0;

    public int $maxParallel = 0;

    /** @var list<string> what the pull before the plan reported */
    public array $warnings = [];

    public Snapshot $after;

    public function __construct(public readonly BoardRef $into) {}

    public static function plan(Snapshot $board, ?string $archive, BoardRef $into, string $now): self
    {
        $plan = new self($into);
        $target = $board->board($into);
        if ($target !== null && ($target->data['kind'] ?? null) === 'decisions') {
            throw new Invalid("{$into} is a decisions board; fold into a work board");
        }
        $decisions = array_filter($board->cards, fn (Card $card) => $card->type() === 'decision');
        $work = array_diff_key($board->cards, $decisions);
        ksort($decisions);
        ksort($work);
        $data = array_map(fn (Card $card) => $card->data, $work);
        $dependents = [];
        foreach ($work as $card) {
            foreach ($card->dependsOn() as $id) {
                if (isset($decisions[$id])) {
                    $dependents[$id][] = $card->id();
                }
            }
        }

        $entries = [];
        $after = [];
        foreach ($decisions as $id => $decision) {
            $open = array_values(array_filter($dependents[$id] ?? [], fn (string $card) => in_array($work[$card]->stage(), self::OPEN, true)));
            if ($decision->stage() === 'proposed' && $open === []) {
                $after[$id] = $plan->spike($decision);

                continue;
            }
            foreach ($dependents[$id] ?? [] as $card) {
                $data[$card]['depends_on'] = array_values(array_diff($data[$card]['depends_on'], [$id]));
            }
            foreach ($open as $card) {
                $data[$card] = match ($decision->stage()) {
                    'proposed' => $plan->ask($data[$card], $decision),
                    'decided', 'superseded' => self::told($data[$card], $decision),
                    default => $data[$card],
                };
            }
            if ($decision->stage() === 'proposed') {
                $plan->questions[$id] = ['title' => $decision->title(), 'cards' => $open];
            }
            $entries[] = self::entry($decision, $decision->stage() === 'proposed' ? $open : []);
            $plan->removed[] = $decision->path;
            $plan->archived[$id] = "{$decision->stage()} {$decision->title()}";
        }
        foreach ($work as $id => $card) {
            $path = "{$into}/{$id}.json";
            if ($data[$id] !== $card->data) {
                $plan->cards[$id] = ['data' => $data[$id], 'path' => $path, 'raw' => false];
            } elseif ($card->path !== $path) {
                $plan->cards[$id] = ['data' => $card->data, 'path' => $path, 'raw' => true];
            }
            if (! $card->board->equals($into)) {
                $plan->moves[$id] = [(string) $card->board, (string) $into];
            }
            $after[$id] = new Card($data[$id], $into, $path, new Rev(''));
            if (Stage::isActive($card->stage())) {
                $plan->active[] = $id;
            }
        }

        [$epics, $boards, $preamble] = $plan->boards($board, $now);
        $kanban = $board->kanban;
        $kanban['version'] = Snapshot::VERSION;
        if (isset($kanban['locked']) && is_array($kanban['locked'])) {
            $kanban['locked'] = array_values(array_intersect($kanban['locked'], Stage::WORK));
        }
        if (self::differs($kanban, $board->kanban)) {
            $kanban['updated'] = $now;
            $plan->files['kanban.json'] = $kanban;
        }
        $written = Archive::add($archive, $entries, $preamble);
        if ($written !== $archive) {
            $plan->archive = $written;
        }

        $plan->after = new Snapshot($kanban, $epics, $boards, $after, $board->problems);
        $plan->report($board);

        return $plan;
    }

    public function isEmpty(): bool
    {
        return $this->cards === [] && $this->removed === [] && $this->files === [] && $this->archive === null;
    }

    public function message(): string
    {
        return "Kanban: fold boards into {$this->into} (".count($this->moves).' cards moved, '.count($this->archived).' archived, '
            .count($this->spikes).' spikes)';
    }

    /**
     * The epics and boards after the fold (the target board, its epic) and the text of the folded decisions boards.
     *
     * @return array{0: array<string, Epic>, 1: array<string, Board>, 2: list<string>}
     */
    private function boards(Snapshot $board, string $now): array
    {
        $into = $this->into;
        $target = $board->board($into)?->data ?? ['title' => Str::headline($into->board), 'body' => '', 'order' => 10, 'wip' => [], 'updated' => $now];
        $before = $target;
        unset($target['kind']);
        $texts = [];
        $preamble = [];
        foreach ($board->boards() as $folded) {
            if ($folded->ref->equals($into)) {
                continue;
            }
            $this->removed[] = $folded->path();
            // a limit set for one of several boards would cap the whole project
            $target['wip'] = [];
            $body = trim((string) ($folded->data['body'] ?? ''));
            if ($body === '') {
                continue;
            }
            if (($folded->data['kind'] ?? null) === 'decisions') {
                $preamble[] = "## {$folded->ref} — {$folded->title()}\n\n{$body}";
            } else {
                $texts[] = "## {$folded->ref} — {$folded->title()}\n\n{$body}";
            }
        }
        $epic = $board->epic($into->epic)?->data ?? ['title' => Str::headline($into->epic), 'goal' => '', 'done_when' => [], 'body' => '', 'order' => 10, 'updated' => $now];
        $epics = [];
        foreach ($board->epics as $slug => $other) {
            if ($slug === $into->epic) {
                continue;
            }
            $this->removed[] = $other->path();
            $text = array_filter([trim((string) ($other->data['goal'] ?? '')),
                ($other->data['done_when'] ?? []) === [] ? '' : "Done when:\n\n".implode("\n", array_map(fn ($item) => "- {$item}", (array) $other->data['done_when'])),
                trim((string) ($other->data['body'] ?? ''))]);
            if ($text !== []) {
                $texts[] = "## {$slug} — {$other->title()}\n\n".implode("\n\n", $text);
            }
        }
        foreach ($texts as $text) {
            if (! str_contains((string) $target['body'], $text)) {
                $target['body'] = self::append((string) $target['body'], $text);
            }
        }
        if (mb_strlen($target['body']) > self::BODY_MAX) {
            throw new Invalid("{$into}: the texts of the folded boards and epics would pass ".self::BODY_MAX.' characters in its body; shorten them first');
        }
        if ($board->epic($into->epic) === null) {
            $this->files["{$into->epic}/epic.json"] = $epic;
        }
        if (self::differs($target, $before) || $board->board($into) === null) {
            $target['updated'] = $now;
            $this->files["{$into}/board.json"] = $target;
        }
        $epics[$into->epic] = new Epic($into->epic, $epic, new Rev(''));

        return [$epics, [(string) $into => new Board($into, $target, new Rev(''))], $preamble];
    }

    /** A proposed decision as a backlog spike on the target board: the same id, its question as a block. */
    private function spike(Card $decision): Card
    {
        $old = $decision->data;
        $body = (string) ($old['body'] ?? '');
        if (trim((string) ($old['why'] ?? '')) !== '') {
            $body = self::append($body, 'Why: '.trim((string) $old['why']));
        }
        $data = [
            'id' => $decision->id(), 'type' => 'spike', 'title' => $decision->title(), 'stage' => 'proposed', 'priority' => $decision->priority(),
            'labels' => $decision->labels(), 'body' => self::fits($decision->id(), $body), 'acceptance' => [], 'depends_on' => [],
            'blocked' => self::question($decision->title()), 'claim' => null, 'work' => null, 'created' => $decision->created(),
            'updated' => $decision->updated(), 'log' => $decision->log(),
        ];
        $data = Transitions::stage($data, 'backlog', 'fold-boards', 'an open question, now a spike');
        $path = "{$this->into}/{$decision->id()}.json";
        $this->cards[$decision->id()] = ['data' => $data, 'path' => $path, 'raw' => false];
        $this->spikes[$decision->id()] = $decision->title();
        if ($decision->path !== $path) {
            $this->moves[$decision->id()] = [(string) $decision->board, (string) $this->into];
            $this->removed[] = $decision->path;
        }

        return new Card($data, $this->into, $path, new Rev(''));
    }

    /**
     * A dependent of a proposed decision: the question as its block (a block it already has stays), the decision in its
     * body, and back to backlog from ready.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function ask(array $data, Card $decision): array
    {
        $id = $decision->id();
        $heading = "## Open question ({$id})";
        if (! str_contains((string) $data['body'], $heading)) {
            $full = "{$heading}\n\n**{$decision->title()}**".(trim((string) ($decision->data['body'] ?? '')) === '' ? ''
                : "\n\n".self::cut(trim((string) $decision->data['body']), $id));
            $short = "{$heading}\n\n**{$decision->title()}** (".Archive::PATH." {$id})";
            $data['body'] = mb_strlen(self::append((string) $data['body'], $full)) <= self::BODY_MAX
                ? self::append((string) $data['body'], $full) : self::fits((string) $data['id'], self::append((string) $data['body'], $short));
        }
        $blocked = $data['blocked'] ?? null;
        if ($blocked === null) {
            $data['blocked'] = self::question($decision->title());
        } elseif (! str_starts_with((string) $blocked, Card::QUESTION)) {
            $this->kept[(string) $data['id']] = (string) $blocked;
        }
        if ($data['stage'] === 'ready') {
            $data = Transitions::stage($data, 'backlog', 'move', "open question {$id}");
        }

        return $data;
    }

    /**
     * A dependent of a decided decision: what was decided, in its body (Context shows notes only once work starts).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function told(array $data, Card $decision): array
    {
        $id = $decision->id();
        $heading = "## Owner decision ({$id})";
        if (str_contains((string) $data['body'], $heading)) {
            return $data;
        }
        $text = implode("\n\n", array_filter([
            "**{$decision->title()}**",
            trim((string) ($decision->data['body'] ?? '')),
            trim((string) ($decision->data['why'] ?? '')) === '' ? '' : 'Why: '.trim((string) $decision->data['why']),
            ($decision->data['superseded_by'] ?? null) === null ? '' : "Superseded by {$decision->data['superseded_by']}.",
        ]));
        $data['body'] = self::fits((string) $data['id'], self::append((string) $data['body'], "{$heading}\n\n".self::cut($text, $id)));

        return $data;
    }

    /**
     * @param  list<string>  $asked  the cards a proposed decision lands on
     * @return array{id: string, title: string, date: string, facts: list<string>, body: string, why: string, resolution: string|null}
     */
    private static function entry(Card $decision, array $asked): array
    {
        $data = $decision->data;
        $facts = [$decision->stage()];
        if (($data['superseded_by'] ?? null) !== null) {
            $facts[] = "superseded by {$data['superseded_by']}";
        }
        if (($data['supersedes'] ?? []) !== []) {
            $facts[] = 'supersedes '.implode(', ', (array) $data['supersedes']);
        }
        if ($asked !== []) {
            $facts[] = 'asked on '.implode(', ', $asked);
        }
        if (isset($data['source']['file'], $data['source']['line'])) {
            $facts[] = "{$data['source']['file']}:{$data['source']['line']}";
        }

        return [
            'id' => $decision->id(), 'title' => $decision->title(), 'date' => (string) (($data['decided_on'] ?? null) ?: substr($decision->created(), 0, 10)),
            'facts' => $facts, 'body' => (string) ($data['body'] ?? ''), 'why' => (string) ($data['why'] ?? ''), 'resolution' => $data['resolution'] ?? null,
        ];
    }

    private function report(Snapshot $before): void
    {
        $this->maxParallel = (int) $before->setting('max_parallel', 6);
        $areas = [];
        foreach ($this->after->cards as $card) {
            if (! in_array($card->stage(), self::OPEN, true)) {
                continue;
            }
            if ($card->areas() === []) {
                $this->noArea[] = $card->id();
            } elseif (in_array($card->stage(), ['backlog', 'ready'], true) && $card->blocked() === null && $this->after->depsSatisfied($card)) {
                $areas += array_fill_keys($card->areas(), true);
            }
        }
        sort($this->noArea);
        $this->startableAreas = count($areas);
    }

    private static function question(string $title): string
    {
        return Card::QUESTION.DecisionsMarkdown::cut($title, self::BLOCKED_MAX - mb_strlen(Card::QUESTION));
    }

    private static function cut(string $text, string $id): string
    {
        $pointer = ' ('.Archive::PATH." {$id})";

        return mb_strlen($text) <= self::EXCERPT ? $text : DecisionsMarkdown::cut($text, self::EXCERPT - mb_strlen($pointer)).$pointer;
    }

    private static function append(string $body, string $section): string
    {
        return trim($body) === '' ? $section : rtrim($body)."\n\n".$section;
    }

    private static function fits(string $id, string $body): string
    {
        if (mb_strlen($body) > self::BODY_MAX) {
            throw new Invalid("{$id}: its body would pass ".self::BODY_MAX.' characters; shorten it first');
        }

        return $body;
    }

    /**
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     */
    private static function differs(array $a, array $b): bool
    {
        unset($a['updated'], $b['updated']);

        return $a != $b;
    }
}
