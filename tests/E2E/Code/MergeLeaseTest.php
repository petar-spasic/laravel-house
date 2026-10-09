<?php

use PetarSpasic\LaravelHouse\Kanban\Code\MergeBeat;
use PetarSpasic\LaravelHouse\Tests\Support\CodeSandbox;
use PetarSpasic\LaravelHouse\Tests\Support\Origin;
use Symfony\Component\Process\Exception\ProcessSignaledException;

beforeEach(function () {
    $this->origin = Origin::create();
    $this->a = CodeSandbox::create(['gates' => ['report' => []]]);
    $this->b = $this->a->twoMachines($this->origin);
    foreach ([$this->a, $this->b] as $machine) {
        $machine->defaults += ['FAKE_DOCKER_SERVE' => '1'];
    }
});

/** An approved card on $machine; with $conflicts, its change meets one already on the remote's main. */
function approvedOn(CodeSandbox $machine, string $title, string $at, bool $conflicts = false): string
{
    $id = $machine->started($title);
    $file = $conflicts ? 'app.php' : strtolower(str_replace(' ', '-', $title)).'.php';
    $machine->commit($id, $file, "<?php\n\nreturn '{$title}';\n");
    $machine->approve($id, at: $at);
    $machine->ok(['sync']);

    return $id;
}

/** A change to app.php on the remote's main, made on $machine. */
function mainMoves(CodeSandbox $machine): void
{
    $machine->commitMain('app.php', "<?php\n\nreturn 'main';\n");
    $machine->sandbox->git('push', '-q', 'origin', 'main');
}

/** @return array<string, mixed>|null the merge lease on the remote's board */
function originLease(Origin $origin): ?array
{
    return json_decode($origin->show('kanban:kanban.json'), true)['merge'] ?? null;
}

it('lets one of two machines racing for a free lease win it; the other waits and leaves no lease commit', function () {
    [$a, $b] = [$this->a, $this->b];
    $y = approvedOn($b, 'Archive notes', '2026-01-01T00:00:00.000+00:00');
    $x = approvedOn($a, 'Tag notes', '2026-01-02T00:00:00.000+00:00', conflicts: true);
    mainMoves($a);
    // A has seen B's card at the front long enough to pass it over
    $a->ok(['sync']);
    $a->seen(1000, [$y]);
    $kanban = PHP_BINARY.' '.$a->root().'/vendor/bin/kanban';
    Origin::racingPush($b->sandbox, "cd {$a->root()} && KANBAN_SYNC=on KANBAN_SESSION= FAKE_DOCKER_DIR={$a->docker} KANBAN_STATE_DIR={$a->state} HOME={$a->home} FAKE_DOCKER_SERVE=1 XDEBUG_MODE=off {$kanban} finish {$x} > {$a->root()}/../race.out 2>&1", ref: 'refs/heads/kanban');

    $lost = $b->kanban(['finish', $y]);

    expect($lost->getExitCode())->toBe(11, $lost->getErrorOutput())
        ->and($lost->getErrorOutput())->toContain('merge lease held by ')->toContain(" for {$x} since ")
        ->and(file_get_contents($a->root().'/../race.out'))->toContain("{$x}: conflicts in app.php; the merger's turn")
        ->and(originLease($this->origin))->toMatchArray(['card' => $x])
        ->and(array_values(array_filter($this->origin->log('kanban'), fn (string $s) => str_starts_with($s, 'merge lease'))))->toBe(["merge lease {$x} taken [owner]"])
        ->and($b->mergeState())->toBeNull();
});

/** Moves the last beat in $machine's merge.json to $at, so its beater beats now; waits until the remote's lease moved. */
function beatNow(CodeSandbox $machine, Origin $origin, string $at = '2026-01-01T00:00:00.000+00:00'): array
{
    $before = originLease($origin);
    $file = $machine->root().'/.git/laravel-house/merge.json';
    file_put_contents($file, json_encode(['beat_at' => $at] + $machine->mergeState()));
    for ($until = microtime(true) + 20; microtime(true) < $until && originLease($origin)['beat'] === $before['beat'];) {
        usleep(200_000);
    }

    return [$before, originLease($origin)];
}

it('beats a held lease, and lets another machine take it over only once it stood still there for 15 minutes', function () {
    [$a, $b] = [$this->a, $this->b];
    $x = approvedOn($a, 'Tag notes', '2026-01-01T00:00:00.000+00:00', conflicts: true);
    mainMoves($a);
    expect($a->kanban(['finish', $x])->getExitCode())->toBe(12);
    $y = approvedOn($b, 'Archive notes', '2026-01-02T00:00:00.000+00:00');
    $b->seen(1000, [$x]);

    [$before, $after] = beatNow($a, $this->origin);
    $waits = $b->kanban(['finish', $y]);

    expect($after['beat'])->not->toBe($before['beat'])
        ->and(array_diff_key($after, ['beat' => 1]))->toBe(array_diff_key($before, ['beat' => 1]))
        ->and($waits->getExitCode())->toBe(11, $waits->getOutput().$waits->getErrorOutput())
        ->and(originLease($this->origin)['card'])->toBe($x);

    $b->ok(['sync']);
    $b->seen(1000, [$x]);
    $main = trim($a->gitIn($this->origin->path, 'rev-parse', 'main'));
    $took = $b->kanban(['finish', $y]);
    $lost = $a->kanban(['finish', $x]);

    expect($took->getExitCode())->toBe(0, $took->getErrorOutput())
        ->and($this->origin->log('kanban'))->toContain("merge lease {$y} taken over from {$before['by']} [owner]")
        ->and($this->origin->log('main')[0])->toBe("{$y}: Archive notes")
        ->and(trim($a->gitIn($this->origin->path, 'rev-parse', 'main^')))->toBe($main)
        ->and($lost->getExitCode())->toBe(8, $lost->getErrorOutput())
        ->and($a->mergeState())->toBeNull()
        ->and(originLease($this->origin))->toBeNull();
});

it('pushes nothing once another machine took its lease over while its checks ran', function () {
    $a = $this->a;
    // the last check: another machine takes the lease over on the remote's board
    $taken = 'd=$(mktemp -d) && git clone -q -b kanban '.escapeshellarg($this->origin->path).' "$d"'
        .' && lease=$(php -r \'echo json_decode(file_get_contents($argv[1]), true)["lease"];\' '.escapeshellarg($a->root().'/.git/laravel-house/merge.json').')'
        .' && sed -i "s/$lease/0123456789abcdef/" "$d/kanban.json"'
        .' && git -C "$d" -c user.name=Ben -c user.email=ben@example.com commit -qam "merge lease taken over"'
        .' && git -C "$d" push -q origin kanban && rm -rf "$d"';
    $a->configure(['gates' => ['report' => []], 'finish' => ['check' => ['test ! -e RED', $taken]]]);
    $a->sandbox->git('commit', '-q', '-am', 'config');
    $a->sandbox->git('push', '-q', 'origin', 'main');
    $x = approvedOn($a, 'Tag notes', '2026-01-01T00:00:00.000+00:00');
    $main = trim($a->gitIn($this->origin->path, 'rev-parse', 'main'));

    $lost = $a->kanban(['finish', $x]);

    expect($lost->getExitCode())->toBe(8, $lost->getOutput().$lost->getErrorOutput())
        ->and($lost->getOutput())->toContain("{$x}: gates and finish.check pass on the merged tree")
        ->and(trim($a->gitIn($this->origin->path, 'rev-parse', 'main')))->toBe($main)
        ->and(originLease($this->origin))->toMatchArray(['id' => '0123456789abcdef', 'card' => $x])
        ->and(originLease($this->origin))->not->toHaveKey('pushing')
        ->and($a->mergeState())->toBeNull()
        ->and($a->sandbox->read($x)['stage'])->toBe('review');
});

it('takes over a lease whose holder died after its push landed, moving its card to done in the same commit', function () {
    [$a, $b] = [$this->a, $this->b];
    $x = approvedOn($a, 'Tag notes', '2026-01-01T00:00:00.000+00:00');
    Origin::racingPush($a->sandbox, 'read lref lsha rest; git push -q origin "$lsha:refs/heads/main"; kill -9 $(cat "'.$a->root().'/.git/laravel-house/merge/finish.pid"); exit 1', ref: 'refs/heads/main');
    try {
        $a->kanban(['finish', $x]);
    } catch (ProcessSignaledException) {
    }
    $merged = $a->mergeState()['merged'];
    $y = approvedOn($b, 'Archive notes', '2026-01-02T00:00:00.000+00:00');
    $b->seen(1000, [$x]);
    $board = $this->origin->show('kanban:kanban.json');

    $took = $b->kanban(['finish', $y]);

    expect($took->getExitCode())->toBe(0, $took->getErrorOutput())
        ->and(array_values(array_filter($this->origin->log('kanban'), fn (string $s) => str_contains($s, 'taken over'))))
        ->toBe(["merge lease {$y} taken over from ".originLeaseBy($board)."; {$x} done (its push landed) [owner]"])
        ->and($b->sandbox->read($x))->toMatchArray(['stage' => 'done'])
        ->and($b->sandbox->read($x)['work']['merge'])->toBe($merged)
        ->and($b->sandbox->read($y))->toMatchArray(['stage' => 'done']);

    // the card's own machine tidies what it still holds of it
    $tidy = $a->kanban(['finish', $x]);
    expect($tidy->getExitCode())->toBe(0, $tidy->getErrorOutput())
        ->and(is_dir($a->worktree($x)))->toBeFalse()
        ->and($a->mergeState())->toBeNull();
});

it('takes over a lease whose holder died before its push landed, leaving its card queued', function () {
    [$a, $b] = [$this->a, $this->b];
    $x = approvedOn($a, 'Tag notes', '2026-01-01T00:00:00.000+00:00');
    Origin::racingPush($a->sandbox, 'kill -9 $(cat "'.$a->root().'/.git/laravel-house/merge/finish.pid"); exit 1', ref: 'refs/heads/main');
    try {
        $a->kanban(['finish', $x]);
    } catch (ProcessSignaledException) {
    }
    $pushing = originLease($this->origin)['pushing'] ?? null;
    $y = approvedOn($b, 'Archive notes', '2026-01-02T00:00:00.000+00:00');
    $b->seen(1000, [$x]);
    $board = $this->origin->show('kanban:kanban.json');

    $took = $b->kanban(['finish', $y]);

    expect($pushing)->toBe($a->mergeState()['merged'])
        ->and($took->getExitCode())->toBe(0, $took->getErrorOutput())
        ->and(array_values(array_filter($this->origin->log('kanban'), fn (string $s) => str_contains($s, 'taken over'))))
        ->toBe(["merge lease {$y} taken over from ".originLeaseBy($board).' [owner]'])
        ->and($b->sandbox->read($x))->toMatchArray(['stage' => 'review'])
        ->and($b->sandbox->read($x)['work']['approved'])->not->toBeNull()
        ->and($this->origin->log('main'))->not->toContain("{$x}: Tag notes")
        ->and($b->sandbox->read($y))->toMatchArray(['stage' => 'done']);
});

it('starts timing a lease again when the monotonic clock is behind what it recorded, as after a reboot with no boot id', function () {
    [$a, $b] = [$this->a, $this->b];
    $x = approvedOn($a, 'Tag notes', '2026-01-01T00:00:00.000+00:00', conflicts: true);
    mainMoves($a);
    expect($a->kanban(['finish', $x])->getExitCode())->toBe(12);
    $y = approvedOn($b, 'Archive notes', '2026-01-02T00:00:00.000+00:00');
    // seen at a time the clock has not reached: one from before the reboot
    $b->seen(-1_000_000, [$x]);

    $waits = $b->kanban(['finish', $y]);

    $seen = json_decode((string) file_get_contents($b->root().'/.git/laravel-house/merge-seen.json'), true);
    $now = hrtime(true) / 1e9;
    expect($waits->getExitCode())->toBe(11, $waits->getErrorOutput())
        ->and($seen['lease']['seen'])->toBeLessThanOrEqual($now)
        ->and($seen['heads'][$x]['since'])->toBeLessThanOrEqual($now);
});

it('starts timing a lease again after a reboot, though the clock has passed what it recorded before it', function () {
    [$a, $b] = [$this->a, $this->b];
    $x = approvedOn($a, 'Tag notes', '2026-01-01T00:00:00.000+00:00', conflicts: true);
    mainMoves($a);
    expect($a->kanban(['finish', $x])->getExitCode())->toBe(12);
    $y = approvedOn($b, 'Archive notes', '2026-01-02T00:00:00.000+00:00');
    $b->seen(1000, [$x]);
    $file = $b->root().'/.git/laravel-house/merge-seen.json';
    file_put_contents($file, json_encode(['boot' => 'another-boot'] + json_decode((string) file_get_contents($file), true)));

    $waits = $b->kanban(['finish', $y]);

    $seen = json_decode((string) file_get_contents($file), true);
    $before = hrtime(true) / 1e9 - 60;
    expect($waits->getExitCode())->toBe(11, $waits->getErrorOutput())
        ->and($seen['boot'])->toBe(trim((string) file_get_contents('/proc/sys/kernel/random/boot_id')))
        ->and($seen['lease']['seen'])->toBeGreaterThan($before)
        ->and($seen['heads'][$x]['since'])->toBeGreaterThan($before);
});

it("keeps the push in flight on the lease at the beater's beats", function () {
    $a = $this->a;
    $x = approvedOn($a, 'Tag notes', '2026-01-01T00:00:00.000+00:00', conflicts: true);
    mainMoves($a);
    expect($a->kanban(['finish', $x])->getExitCode())->toBe(12);
    $merged = trim($a->sandbox->git('rev-parse', 'main'));
    file_put_contents($a->root().'/.git/laravel-house/merge.json', json_encode(['phase' => 'pushing', 'merged' => $merged] + $a->mergeState()));

    [$before, $after] = beatNow($a, $this->origin);

    expect($after['beat'])->not->toBe($before['beat'])
        ->and($after['pushing'] ?? null)->toBe($merged);
});

it('has its beater beat at once when the clock was set back past its last beat', function () {
    $a = $this->a;
    $x = approvedOn($a, 'Tag notes', '2026-01-01T00:00:00.000+00:00', conflicts: true);
    mainMoves($a);
    expect($a->kanban(['finish', $x])->getExitCode())->toBe(12);

    [$before, $after] = beatNow($a, $this->origin, '2099-01-01T00:00:00.000+00:00');

    expect($after['beat'])->not->toBe($before['beat']);
});

it('has its beater find the lease taken over, write so and end', function () {
    [$a, $b] = [$this->a, $this->b];
    $x = approvedOn($a, 'Tag notes', '2026-01-01T00:00:00.000+00:00', conflicts: true);
    mainMoves($a);
    expect($a->kanban(['finish', $x])->getExitCode())->toBe(12);
    $y = approvedOn($b, 'Archive notes', '2026-01-02T00:00:00.000+00:00');
    $b->seen(1000, [$x]);
    expect($b->kanban(['finish', $y])->getExitCode())->toBe(0);
    $state = $a->root().'/.git/laravel-house/merge.json';

    file_put_contents($state, json_encode(['beat_at' => '2026-01-01T00:00:00.000+00:00'] + $a->mergeState()));
    for ($until = microtime(true) + 20; microtime(true) < $until && ! isset($a->mergeState()['lost']);) {
        usleep(200_000);
    }
    $beater = fopen($a->root().'/.git/laravel-house/'.MergeBeat::LOCK, 'c');
    for ($until = microtime(true) + 10; microtime(true) < $until && ! flock($beater, LOCK_EX | LOCK_NB);) {
        usleep(100_000);
    }

    expect($a->mergeState()['lost'] ?? '')->toStartWith('merge lease lost: ')
        ->and(flock($beater, LOCK_EX | LOCK_NB))->toBeTrue()
        ->and($a->kanban(['finish', $x])->getExitCode())->toBe(8)
        ->and($a->mergeState())->toBeNull();
});

function originLeaseBy(string $board): string
{
    return json_decode($board, true)['merge']['by'];
}

it('knows a lease as its own when its push errored but landed, and gives it back leaving the board as it was', function () {
    $b = $this->b;
    $y = approvedOn($b, 'Archive notes', '2026-01-01T00:00:00.000+00:00');
    $board = $this->origin->show('kanban:kanban.json');
    Origin::racingPush($b->sandbox, 'read lref lsha rest; git -C "'.$b->root().'/docs/kanban" push -q origin "$lsha:refs/heads/kanban"; exit 1', ref: 'refs/heads/kanban');

    $run = $b->kanban(['finish', $y]);

    expect($run->getExitCode())->toBe(0, $run->getErrorOutput())
        ->and($b->sandbox->read($y)['stage'])->toBe('done')
        ->and(originLease($this->origin))->toBeNull()
        ->and($this->origin->show('kanban:kanban.json'))->toBe($board);
});

it('knows a lease as its own when it died while its push landed, and merges under it', function () {
    $b = $this->b;
    $y = approvedOn($b, 'Archive notes', '2026-01-01T00:00:00.000+00:00');
    Origin::racingPush($b->sandbox, 'read lref lsha rest; git -C "'.$b->root().'/docs/kanban" push -q origin "$lsha:refs/heads/kanban"; kill -9 $(cat "'.$b->root().'/.git/laravel-house/merge/finish.pid"); exit 1', ref: 'refs/heads/kanban');
    try {
        $b->kanban(['finish', $y]);
    } catch (ProcessSignaledException) {
    }
    $state = $b->mergeState();
    $held = originLease($this->origin);

    $run = $b->kanban(['finish', $y]);

    expect($state)->toMatchArray(['phase' => 'acquiring', 'card' => $y])
        ->and($held)->toMatchArray(['id' => $state['lease'], 'card' => $y])
        ->and($run->getExitCode())->toBe(0, $run->getErrorOutput())
        ->and($run->getErrorOutput())->not->toContain('held by')
        ->and($b->sandbox->read($y)['stage'])->toBe('done')
        ->and(array_values(array_filter($this->origin->log('kanban'), fn (string $s) => str_starts_with($s, 'merge lease'))))
        ->toBe(["merge lease {$y} released [owner]", "merge lease {$y} beat [owner]", "merge lease {$y} taken [owner]", "merge lease {$y} taken [owner]"]);
});

it('gives back a lease it died holding when the board refuses its card as it resumes', function () {
    $b = $this->b;
    $y = approvedOn($b, 'Archive notes', '2026-01-01T00:00:00.000+00:00');
    Origin::racingPush($b->sandbox, 'read lref lsha rest; git -C "'.$b->root().'/docs/kanban" push -q origin "$lsha:refs/heads/kanban"; kill -9 $(cat "'.$b->root().'/.git/laravel-house/merge/finish.pid"); exit 1', ref: 'refs/heads/kanban');
    try {
        $b->kanban(['finish', $y]);
    } catch (ProcessSignaledException) {
    }
    $held = originLease($this->origin);
    // blocked on the other machine; this one has not pulled it yet
    $this->a->ok(['sync']);
    $this->a->ok(['set', $y, 'blocked=waits on the owner']);
    $this->a->ok(['sync']);
    touch($b->root().'/.git/laravel-house/sync.tick');

    $run = $b->kanban(['finish', $y]);

    expect($held)->toMatchArray(['card' => $y])
        ->and($run->getExitCode())->toBe(3, $run->getOutput().$run->getErrorOutput())
        ->and($run->getErrorOutput())->toContain("{$y} is blocked: waits on the owner")
        ->and(originLease($this->origin))->toBeNull()
        ->and($b->mergeState())->toBeNull();
});

it('writes the lease on this machine only while sync is off', function () {
    $a = $this->a;
    $a->defaults['KANBAN_SYNC'] = 'off';
    $x = approvedOn($a, 'Tag notes', '2026-01-01T00:00:00.000+00:00', conflicts: true);
    mainMoves($a);

    expect($a->kanban(['finish', $x])->getExitCode())->toBe(12)
        ->and($a->lease())->toMatchArray(['card' => $x])
        ->and(originLease($this->origin))->toBeNull()
        ->and($a->sandbox->boardLog())->toContain("merge lease {$x} taken [owner]");
});
