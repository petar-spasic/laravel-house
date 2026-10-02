<?php

use PetarSpasic\LaravelHouse\Tests\Support\Origin;
use PetarSpasic\LaravelHouse\Tests\Support\Sandbox;

/**
 * Turns an installed board into a version 1 board of several boards: project/work, a decisions board, and an `app` epic
 * with a frontend and a backend board. Every kind of decision card is on it, with dependents in each relevant stage.
 *
 * @param  array<string, array<string, mixed>>  $overrides  card id => fields replaced on that card
 */
function multiBoard(Sandbox $s, array $overrides = []): void
{
    $root = $s->root.'/docs/kanban/';
    $put = function (string $path, array $data) use ($root) {
        @mkdir(dirname($root.$path), 0775, true);
        file_put_contents($root.$path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
    };
    $at = fn (int $day) => sprintf('2026-09-%02dT10:00:00.000+00:00', $day);
    $kanban = json_decode(file_get_contents($root.'kanban.json'), true);
    $put('kanban.json', ['version' => 1, 'locked' => ['doing', 'review', 'done', 'superseded']] + $kanban);
    $put('project/work/board.json', ['title' => 'Work', 'kind' => 'work', 'body' => '', 'order' => 20, 'wip' => ['doing' => 6], 'updated' => $at(1)]);
    $put('project/decisions/board.json', ['title' => 'Decisions', 'kind' => 'decisions', 'body' => 'Owner decisions, newest first.', 'order' => 10, 'wip' => (object) [], 'updated' => $at(1)]);
    $put('app/epic.json', ['title' => 'App', 'goal' => 'Ship the notes app.', 'done_when' => ['Notes sync between devices'], 'body' => '', 'order' => 20, 'updated' => $at(1)]);
    $put('app/frontend/board.json', ['title' => 'Frontend', 'kind' => 'work', 'body' => 'Pages and components.', 'order' => 10, 'wip' => ['doing' => 2], 'updated' => $at(1)]);
    $put('app/backend/board.json', ['title' => 'Backend', 'kind' => 'work', 'body' => '', 'order' => 20, 'wip' => (object) [], 'updated' => $at(1)]);

    $log = fn (int $day, string $suffix) => [['id' => 'G0G'.$suffix, 'at' => $at($day), 'by' => 'owner', 'event' => 'created']];
    $decision = fn (string $id, string $stage, string $title, string $body, string $why, ?string $decidedOn, int $day, array $extra = []) => $extra + [
        'id' => $id, 'type' => 'decision', 'title' => $title, 'stage' => $stage, 'priority' => 'normal', 'labels' => [], 'body' => $body,
        'why' => $why, 'decided_on' => $decidedOn, 'supersedes' => [], 'superseded_by' => null, 'resolution' => null, 'source' => null,
        'created' => $at($day), 'updated' => $at($day), 'log' => $log($day, substr($id, -5)),
    ];
    $work = fn (string $id, string $stage, string $title, array $labels, array $dependsOn, int $day, array $extra = []) => $extra + [
        'id' => $id, 'type' => 'feature', 'title' => $title, 'stage' => $stage, 'priority' => 'normal', 'labels' => $labels,
        'body' => "Build {$title}.", 'acceptance' => [['id' => 1, 'text' => "{$title} works", 'done' => false]], 'depends_on' => $dependsOn,
        'blocked' => null, 'claim' => null, 'work' => null, 'created' => $at($day), 'updated' => $at($day), 'log' => $log($day, substr($id, -5)),
    ];
    $cards = [
        'project/decisions' => [
            $decision('ACME-DEC0P1', 'proposed', 'Which editor library', 'A plain textarea or a rich editor.', '', null, 5),
            $decision('ACME-DEC0P2', 'proposed', 'Should exports include comments', 'Comments are private today.', 'Some teams asked.', null, 6),
            $decision('ACME-DEC0D1', 'decided', 'Search is a Postgres index', 'Titles weigh above bodies.', 'One datastore to run.', '2026-09-02', 2,
                ['supersedes' => ['ACME-DEC0S1']]),
            $decision('ACME-DEC0S1', 'superseded', 'Search uses a separate engine', 'A search service beside the app.', 'Speed.', '2026-08-20', 1,
                ['superseded_by' => 'ACME-DEC0D1']),
            $decision('ACME-DEC0X1', 'dropped', 'Built-in video calls', 'Start a call from a note.', '', null, 3, ['resolution' => 'out of scope']),
        ],
        'app/frontend' => [
            $work('ACME-FE0001', 'ready', 'Editor toolbar', ['area:editor'], ['ACME-DEC0P1'], 7),
            $work('ACME-FE0002', 'backlog', 'Editor shortcuts', ['area:editor'], ['ACME-DEC0P1'], 7, ['blocked' => 'waiting on design']),
            $work('ACME-FE0003', 'backlog', 'Note list page', ['area:pages'], [], 7),
        ],
        'app/backend' => [
            $work('ACME-BE0001', 'backlog', 'Search endpoint', ['area:search'], ['ACME-DEC0D1'], 8),
            $work('ACME-BE0002', 'done', 'Search index', ['area:search'], ['ACME-DEC0D1'], 8, ['work' => [
                'branch' => 'card/acme-be0002-search-index', 'base' => 'main', 'merge' => 'abc1234', 'started' => $at(8), 'finished' => $at(9)]]),
        ],
        'project/work' => [
            $work('ACME-WK0001', 'backlog', 'Invite links', [], ['ACME-DEC0X1'], 9),
        ],
    ];
    foreach ($cards as $board => $list) {
        foreach ($list as $card) {
            $card = array_replace($card, $overrides[$card['id']] ?? []);
            if ($card['id'] === 'ACME-FE0003') {
                // out of the canonical key order: a move that re-encodes the card would show
                ksort($card);
            }
            $put("{$board}/{$card['id']}.json", $card);
        }
    }
    $s->boardGit('add', '-A');
    $s->boardGit('commit', '-q', '-m', 'Board of an older release');
}

/** @return array<string, mixed> */
function boardFile(Sandbox $s, string $path): array
{
    return json_decode((string) file_get_contents($s->root.'/docs/kanban/'.$path), true, 64, JSON_THROW_ON_ERROR);
}

function oldBoard(): Sandbox
{
    $s = Sandbox::create();
    $s->install('ACME');
    multiBoard($s);

    return $s;
}

/** An origin holding a published version 1 board, and two attached clones. */
function sharedOldBoard(): array
{
    $origin = Origin::create();
    $seed = Sandbox::create('seed')->addRemote($origin);
    $seed->install('ACME');
    $seed->git('commit', '-q', '-am', 'Ignore the board worktree');
    $seed->git('push', '-q', 'origin', 'main');
    multiBoard($seed);
    $seed->ok('sync');
    $a = $origin->clone('a');
    $b = $origin->clone('b');
    foreach ([$a, $b] as $clone) {
        $clone->ok('attach');
    }

    return [$origin, $a, $b];
}

const SYNC_ON = ['KANBAN_SYNC' => 'on'];

it('folds every board into one, archives the decisions and puts open questions on the cards they hold up', function () {
    $s = oldBoard();
    $raw = file_get_contents($s->root.'/docs/kanban/app/frontend/ACME-FE0003.json');
    $commits = count($s->boardLog());

    $out = $s->ok('fold-boards');

    expect(count($s->boardLog()))->toBe($commits + 1)
        ->and($s->boardLog()[0])->toBe('Kanban: fold boards into project/work (6 cards moved, 4 archived, 1 spikes) [owner]')
        ->and(trim($s->boardGit('status', '--porcelain')))->toBe('')
        ->and($out)->toContain("moved ACME-FE0003 app/frontend → project/work\n")
        ->toContain("archived ACME-DEC0D1 decided Search is a Postgres index\n")
        ->toContain('spike ACME-DEC0P2 Should exports include comments')
        ->toContain('folded into project/work: 6 moved, 4 archived, 1 spikes');

    // one board, version 2
    $files = array_map(fn (string $f) => substr($f, strlen($s->root.'/docs/kanban/')), glob($s->root.'/docs/kanban/{*,*/*,*/*/*}.{json,md}', GLOB_BRACE));
    sort($files);
    expect($files)->toBe(['README.md', 'decisions.md', 'kanban.json', 'project/epic.json', 'project/work/ACME-BE0001.json', 'project/work/ACME-BE0002.json',
        'project/work/ACME-DEC0P2.json', 'project/work/ACME-FE0001.json', 'project/work/ACME-FE0002.json', 'project/work/ACME-FE0003.json',
        'project/work/ACME-WK0001.json', 'project/work/board.json'])
        ->and(boardFile($s, 'kanban.json'))->toMatchArray(['version' => 2, 'locked' => ['doing', 'review', 'done']]);
    $board = boardFile($s, 'project/work/board.json');
    expect($board)->not->toHaveKey('kind')
        ->and($board['wip'])->toBe([])
        ->and($board['body'])->toBe("## app/frontend — Frontend\n\nPages and components.\n\n## app — App\n\nShip the notes app.\n\nDone when:\n\n- Notes sync between devices");

    // an unchanged card moves byte for byte
    expect(file_get_contents($s->root.'/docs/kanban/project/work/ACME-FE0003.json'))->toBe($raw);

    // proposed with open dependents: a question on each, ready goes back to backlog, a block of another kind stays
    $toolbar = $s->read('ACME-FE0001');
    expect($toolbar)->toMatchArray(['stage' => 'backlog', 'blocked' => 'question: Which editor library', 'depends_on' => []])
        ->and($toolbar['body'])->toBe("Build Editor toolbar.\n\n## Open question (ACME-DEC0P1)\n\n**Which editor library**\n\nA plain textarea or a rich editor.")
        ->and(end($toolbar['log']))->toMatchArray(['event' => 'stage', 'from' => 'ready', 'to' => 'backlog', 'reason' => 'open question ACME-DEC0P1']);
    $shortcuts = $s->read('ACME-FE0002');
    expect($shortcuts)->toMatchArray(['stage' => 'backlog', 'blocked' => 'waiting on design', 'depends_on' => []])
        ->and($shortcuts['body'])->toContain('## Open question (ACME-DEC0P1)')
        ->and($out)->toContain('kept block ACME-FE0002: waiting on design');

    // proposed, nobody waiting: a backlog spike with the same id
    $spike = $s->read('ACME-DEC0P2');
    expect($spike)->toMatchArray(['type' => 'spike', 'stage' => 'backlog', 'blocked' => 'question: Should exports include comments',
        'body' => "Comments are private today.\n\nWhy: Some teams asked.", 'acceptance' => [], 'created' => '2026-09-06T10:00:00.000+00:00'])
        ->and($spike)->not->toHaveKeys(['why', 'decided_on', 'supersedes', 'superseded_by', 'resolution', 'source'])
        ->and(end($spike['log']))->toMatchArray(['event' => 'stage', 'from' => 'proposed', 'to' => 'backlog', 'via' => 'fold-boards']);

    // decided: the link goes, an open dependent carries what was decided; done and dropped dependents only lose the link
    $endpoint = $s->read('ACME-BE0001');
    expect($endpoint['depends_on'])->toBe([])
        ->and($endpoint['body'])->toBe("Build Search endpoint.\n\n## Owner decision (ACME-DEC0D1)\n\n**Search is a Postgres index**\n\nTitles weigh above bodies.\n\nWhy: One datastore to run.")
        ->and($s->read('ACME-BE0002'))->toMatchArray(['stage' => 'done', 'depends_on' => [], 'body' => 'Build Search index.'])
        ->and($s->read('ACME-WK0001'))->toMatchArray(['depends_on' => [], 'body' => 'Build Invite links.', 'blocked' => null]);

    // the archive: one section per decision, by date then id, the decisions board's text on top
    expect(file_get_contents($s->root.'/docs/kanban/decisions.md'))->toBe(<<<'MD'
        # Decisions

        The owner's decisions, kept read only. The ones that still bind are rules in the CLAUDE.md files; an open
        question rides on the card it holds up, as a `question:` block.

        ## project/decisions — Decisions

        Owner decisions, newest first.

        ### ACME-DEC0S1 — Search uses a separate engine

        *2026-08-20 · superseded · superseded by ACME-DEC0D1*

        A search service beside the app.

        **Why:** Speed.

        ### ACME-DEC0D1 — Search is a Postgres index

        *2026-09-02 · decided · supersedes ACME-DEC0S1*

        Titles weigh above bodies.

        **Why:** One datastore to run.

        ### ACME-DEC0X1 — Built-in video calls

        *2026-09-03 · dropped*

        Start a call from a note.

        **Resolution:** out of scope

        ### ACME-DEC0P1 — Which editor library

        *2026-09-05 · proposed · asked on ACME-FE0001, ACME-FE0002*

        A plain textarea or a rich editor.

        MD);

    // the version 1 gate is lifted
    expect($s->ok('validate'))->toContain('ok: 7 cards on 1 boards')
        ->and($s->ok('list'))->toContain('ACME-FE0001')
        ->and($s->kanban(['promote', 'ACME-WK0001'])->getOutput())->toBe("refused ACME-WK0001: R1 no area:* label\n");
});

it('prints the plan on a dry run and writes nothing', function () {
    $s = oldBoard();
    $commits = $s->boardLog();

    $out = $s->ok(['fold-boards', '--dry-run']);

    expect($out)->toContain("moved ACME-FE0001 app/frontend → project/work\n")
        ->toContain("folded board app/frontend\n")->toContain("folded board project/decisions\n")
        ->toContain("archived ACME-DEC0P1 proposed Which editor library\n")
        ->toContain("question ACME-DEC0P1 Which editor library: on ACME-FE0001, ACME-FE0002 (2 cards: fold them into one first)\n")
        ->toContain("no area: ACME-DEC0P2, ACME-WK0001 (promote refuses a card without an area:* label)\n")
        ->toContain("startable areas: 2 of max_parallel 6\n")
        ->toContain('would fold into project/work: 6 moved, 4 archived, 1 spikes; nothing written')
        ->and($s->boardLog())->toBe($commits)
        ->and(trim($s->boardGit('status', '--porcelain')))->toBe('')
        ->and(is_file($s->root.'/docs/kanban/decisions.md'))->toBeFalse()
        ->and($s->kanban('list')->getExitCode())->toBe(3);
});

it('changes nothing when run again, and folds a decision card pushed by a clone still on the old release', function () {
    $s = oldBoard();
    $s->ok('fold-boards');
    $commits = $s->boardLog();
    $archive = file_get_contents($s->root.'/docs/kanban/decisions.md');

    expect($s->ok('fold-boards'))->toBe("nothing to fold: one board, project/work, at version 2\n")
        ->and($s->ok(['fold-boards', '--dry-run']))->toBe("nothing to fold: one board, project/work, at version 2\n")
        ->and($s->boardLog())->toBe($commits);

    $straggler = boardFile($s, 'project/work/ACME-BE0002.json');
    file_put_contents($s->root.'/docs/kanban/project/work/ACME-DEC0D2.json', json_encode([
        'id' => 'ACME-DEC0D2', 'type' => 'decision', 'title' => 'Tags are lowercase', 'stage' => 'decided', 'priority' => 'normal', 'labels' => [],
        'body' => 'One spelling per tag.', 'why' => '', 'decided_on' => '2026-09-10', 'supersedes' => [], 'superseded_by' => null, 'resolution' => null,
        'source' => null, 'created' => $straggler['created'], 'updated' => $straggler['created'], 'log' => [],
    ], JSON_PRETTY_PRINT)."\n");
    $s->boardGit('add', '-A');
    $s->boardGit('commit', '-q', '-m', 'A decision from an older release');

    expect($s->ok('fold-boards'))->toContain('archived ACME-DEC0D2 decided Tags are lowercase')
        ->and(file_get_contents($s->root.'/docs/kanban/decisions.md'))->toStartWith(substr($archive, 0, -1))
        ->toEndWith("### ACME-DEC0D2 — Tags are lowercase\n\n*2026-09-10 · decided*\n\nOne spelling per tag.\n")
        ->and($s->ok('validate'))->toContain('ok: 7 cards');
});

it('waits while a card is in doing or review, and writes nothing', function () {
    $s = Sandbox::create();
    $s->install('ACME');
    multiBoard($s, ['ACME-FE0003' => ['stage' => 'doing', 'claim' => ['by' => 'test@host', 'session' => null, 'at' => '2026-09-07T10:00:00.000+00:00']]]);
    $commits = $s->boardLog();

    $run = $s->kanban('fold-boards');

    expect($run->getExitCode())->toBe(3)
        ->and($run->getErrorOutput())->toContain('fold-boards waits until nothing is in doing or review: ACME-FE0003 (drain the board first)')
        ->and($s->boardLog())->toBe($commits)
        ->and($s->ok(['fold-boards', '--dry-run']))->toContain('in doing or review: ACME-FE0003');
});

it('waits while the runtime of the separate package is still in use', function () {
    $s = oldBoard();
    @mkdir($s->root.'/.git/laravel-kanban/agents', 0775, true);
    file_put_contents($s->root.'/.git/laravel-kanban/agents/a2.json', json_encode(['card' => 'ACME-FE0001']));
    $commits = $s->boardLog();

    $run = $s->kanban('fold-boards');

    expect($run->getExitCode())->toBe(1)
        ->and($run->getOutput())->toContain('kept .git/laravel-kanban: 1 agent(s) still working')
        ->toContain('stopped: .git/laravel-kanban is still there (see above); fold-boards waits for it')
        ->and($s->boardLog())->toBe($commits);

    unlink($s->root.'/.git/laravel-kanban/agents/a2.json');
    expect($s->ok('fold-boards'))->toContain('moved .git/laravel-kanban')->toContain('folded into project/work')
        ->and($s->root.'/.git/laravel-kanban')->not->toBeDirectory();
});

it('lands one fold on origin when two clones run it', function () {
    [$origin, $a, $b] = sharedOldBoard();

    expect($a->ok('fold-boards', SYNC_ON))->toContain('folded into project/work')
        ->and($b->ok('fold-boards', SYNC_ON))->toBe("nothing to fold: one board, project/work, at version 2\n")
        ->and(array_values(array_filter($origin->log('kanban'), fn (string $s) => str_starts_with($s, 'Kanban: fold boards'))))->toHaveCount(1)
        ->and(file_get_contents($b->root.'/docs/kanban/decisions.md'))->toBe(file_get_contents($a->root.'/docs/kanban/decisions.md'))
        ->and($b->ok('validate'))->toContain('ok: 7 cards on 1 boards');

    // at the same moment: one push lands, the other clone finds the fold done
    [$origin, $a, $b] = sharedOldBoard();
    $runs = [$a->start(['fold-boards'], SYNC_ON), $b->start(['fold-boards'], SYNC_ON)];
    foreach ($runs as $run) {
        $run->wait();
        expect($run->getExitCode())->toBe(0, $run->getErrorOutput());
    }
    $a->ok('sync');
    $b->ok('sync');
    expect(array_values(array_filter($origin->log('kanban'), fn (string $s) => str_starts_with($s, 'Kanban: fold boards'))))->toHaveCount(1)
        ->and(trim($a->boardGit('rev-parse', 'HEAD')))->toBe(trim($b->boardGit('rev-parse', 'HEAD')));
});

it('keeps unpushed edits of a folded clone: a moved card merged at its new path, an archived decision card set aside', function () {
    [$origin, $a, $b] = sharedOldBoard();
    $a->ok('fold-boards', SYNC_ON);
    // b, still behind, edits a work card and a decision card the fold moved and archived
    $page = $b->root.'/docs/kanban/app/frontend/ACME-FE0003.json';
    file_put_contents($page, str_replace('"Build Note list page."', '"Build Note list page, sorted by date."', file_get_contents($page)));
    $decision = $b->root.'/docs/kanban/project/decisions/ACME-DEC0D1.json';
    file_put_contents($decision, str_replace('Titles weigh above bodies.', 'Titles weigh above bodies, tags above both.', file_get_contents($decision)));
    $b->boardGit('commit', '-q', '-am', 'Edits from before the fold');
    $edited = file_get_contents($decision);

    $run = $b->kanban('fold-boards', SYNC_ON);

    $displaced = glob($b->root.'/.git/laravel-house/displaced/ACME-DEC0D1.*.json');
    expect($run->getExitCode())->toBe(0, $run->getErrorOutput())
        ->and($run->getOutput())->toContain('warning: ACME-DEC0D1 was deleted on origin; the edits made here are kept in .git/laravel-house/displaced/ACME-DEC0D1.')
        ->toContain('nothing to fold')
        ->and($displaced)->toHaveCount(1)
        ->and(file_get_contents($displaced[0]))->toBe($edited)
        ->and($b->read('ACME-FE0003')['body'])->toBe('Build Note list page, sorted by date.')
        ->and(is_file($b->root.'/docs/kanban/project/work/ACME-FE0003.json'))->toBeTrue()
        ->and(trim($b->boardGit('status', '--porcelain')))->toBe('')
        ->and($b->ok('validate'))->toContain('ok: 7 cards on 1 boards');

    $b->ok('sync');
    $a->ok('sync');
    expect($a->read('ACME-FE0003')['body'])->toBe('Build Note list page, sorted by date.');
});

it('folds into a board it creates, and never into a decisions board', function () {
    $s = oldBoard();

    $refused = $s->kanban(['fold-boards', '--into=project/decisions']);
    expect($refused->getExitCode())->toBe(2)
        ->and($refused->getErrorOutput())->toContain('project/decisions is a decisions board; fold into a work board');

    expect($s->ok(['fold-boards', '--into=core/main']))->toContain('folded into core/main: 7 moved')
        ->and(boardFile($s, 'core/epic.json')['title'])->toBe('Core')
        ->and(boardFile($s, 'core/main/board.json'))->toMatchArray(['title' => 'Main', 'wip' => []])
        ->and(glob($s->root.'/docs/kanban/core/main/ACME-*.json'))->toHaveCount(7)
        ->and(glob($s->root.'/docs/kanban/{project,app}/{*,*/*}.json', GLOB_BRACE))->toBe([])
        ->and($s->ok('validate'))->toContain('ok: 7 cards on 1 boards');
});
