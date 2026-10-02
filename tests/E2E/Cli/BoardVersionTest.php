<?php

use PetarSpasic\LaravelHouse\Tests\Support\Origin;
use PetarSpasic\LaravelHouse\Tests\Support\Sandbox;
use PetarSpasic\LaravelHouse\Tests\Support\UiSandbox;

const OLD_BOARD = 'board version 1: the owner runs /implement-kanban';

/** Turns an installed board into one an older release wrote: version 1, board kinds, a decisions board with a decision card. */
function olderBoard(Sandbox $s, string $card = 'ACME-0LDDEC'): void
{
    $put = fn (string $path, array $data) => file_put_contents($s->root.'/docs/kanban/'.$path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    $kanban = json_decode(file_get_contents($s->root.'/docs/kanban/kanban.json'), true);
    $put('kanban.json', ['version' => 1, 'locked' => ['doing', 'review', 'done', 'superseded']] + $kanban);
    $work = json_decode(file_get_contents($s->root.'/docs/kanban/project/work/board.json'), true);
    $put('project/work/board.json', ['kind' => 'work'] + $work);
    @mkdir($s->root.'/docs/kanban/project/decisions');
    $put('project/decisions/board.json', ['title' => 'Decisions', 'kind' => 'decisions', 'body' => '', 'order' => 10, 'wip' => (object) [], 'updated' => '2026-09-01T10:00:00.000+00:00']);
    $put("project/decisions/{$card}.json", [
        'id' => $card, 'type' => 'decision', 'title' => 'One workspace per team', 'stage' => 'decided', 'priority' => 'normal', 'labels' => [],
        'body' => 'Teams get a workspace each.', 'why' => 'Billing follows teams.', 'decided_on' => '2026-09-01', 'supersedes' => [],
        'superseded_by' => null, 'resolution' => null, 'source' => null, 'created' => '2026-09-01T10:00:00.000+00:00',
        'updated' => '2026-09-01T10:00:00.000+00:00', 'log' => [['id' => '0LD0LD0L', 'at' => '2026-09-01T10:00:00.000+00:00', 'by' => 'owner', 'event' => 'created']],
    ]);
    $s->boardGit('add', '-A');
    $s->boardGit('commit', '-q', '-m', 'Board of an older release');
}

it('refuses every board command on a version 1 board with one line', function (array $args) {
    $s = Sandbox::create();
    $s->install('ACME');
    olderBoard($s);
    $commits = $s->boardLog();

    $run = $s->kanban($args);

    expect($run->getExitCode())->toBe(3)
        ->and($run->getErrorOutput())->toBe(OLD_BOARD."\n")
        ->and($s->boardLog())->toBe($commits);
})->with([
    'list' => [['list']],
    'show' => [['show', 'ACME-0LDDEC']],
    'new' => [['new', 'project/work', 'Fresh card']],
    'next' => [['next']],
    'status' => [['status']],
    'validate' => [['validate']],
    'board' => [['board', 'project/work', 'Renamed']],
]);

it('says so at session start and in the UI, and doctor reports it among its checks', function () {
    $s = Sandbox::create();
    $s->install('ACME');
    olderBoard($s);

    $start = $s->kanban(['hook', 'session-start'], input: json_encode(['session_id' => 'session-1', 'cwd' => $s->root, 'hook_event_name' => 'SessionStart']));
    expect($start->getExitCode())->toBe(0)->and($start->getOutput())->toBe(OLD_BOARD."\n");

    $doctor = $s->kanban('doctor');
    expect($doctor->getExitCode())->toBe(1)
        ->and($doctor->getOutput())->toContain('fail '.OLD_BOARD)
        ->toContain('ok board attached at docs/kanban')
        ->toContain('ok merge driver');
    $fix = $s->kanban(['doctor', '--fix']);
    expect($fix->getOutput())->toContain('fix: board attached at docs/kanban')->toContain('fail '.OLD_BOARD)
        ->and($fix->getErrorOutput())->toBe('');

    UiSandbox::boot($s->root);
    $this->getJson('/kanban/_api/boards')->assertOk()->assertJsonPath('epics', [])->assertJsonPath('notices', [OLD_BOARD]);
    $this->getJson('/kanban/_api/project/work')->assertStatus(422)->assertJsonPath('message', OLD_BOARD);
    $this->getJson('/kanban/_api/cards/ACME-0LDDEC')->assertStatus(422)->assertJsonPath('message', OLD_BOARD);
});

it('still syncs a version 1 board and attaches it on a new clone', function () {
    $origin = Origin::create();
    $seed = Sandbox::create('seed')->addRemote($origin);
    $seed->install('ACME');
    olderBoard($seed);
    expect($seed->ok('sync'))->toBe("sync: pushed 2 commit(s)\n");

    $a = $origin->clone('a');
    expect($a->ok('attach'))->toContain('attached origin/kanban at docs/kanban');
    $card = $seed->root.'/docs/kanban/project/decisions/ACME-0LDDEC.json';
    file_put_contents($card, str_replace('Teams get a workspace each.', 'Teams get a workspace each, billed per seat.', file_get_contents($card)));
    $seed->boardGit('commit', '-q', '-am', 'Edited by an older release');

    expect($seed->ok('sync'))->toBe("sync: pushed 1 commit(s)\n")
        ->and($a->ok('sync'))->toBe("sync: pulled 1 commit(s)\n")
        ->and(file_get_contents($a->root.'/docs/kanban/project/decisions/ACME-0LDDEC.json'))->toContain('billed per seat')
        ->and($a->kanban('list')->getErrorOutput())->toBe(OLD_BOARD."\n");
});
