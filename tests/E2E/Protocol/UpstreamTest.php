<?php

use Composer\InstalledVersions;
use PetarSpasic\LaravelHouse\Tests\Support\ProtocolSandbox;
use PetarSpasic\LaravelHouse\Tests\Support\Sandbox;
use Symfony\Component\Process\ExecutableFinder;

function lastOf(array $items): mixed
{
    return $items[array_key_last($items)];
}

beforeEach(function () {
    $sandbox = Sandbox::create('orchard');
    file_put_contents($sandbox->root.'/.env', "APP_NAME=\"Acme Notes\"\nAPP_URL=http://notes.test\nLOCAL_APP_URL=http://198.18.0.7:8000\n");
    file_put_contents($sandbox->root.'/composer.json', json_encode(['name' => 'orbit/horizon']));
    file_put_contents($sandbox->root.'/composer.lock', json_encode(['packages' => [['name' => 'laravel/framework']], 'packages-dev' => [['name' => 'laravel/horizon']]]));
    file_put_contents($sandbox->root.'/package-lock.json', json_encode(['packages' => ['' => ['name' => 'orbit'], 'node_modules/vite' => []]]));
    $sandbox->install('LEDGER');
    $this->p = new ProtocolSandbox($sandbox);
    $sandbox->git('remote', 'add', 'origin', 'git@github.com:northwind/ledger-app.git');
    [$this->id, $this->wt] = $this->p->started('Export notes');
    $this->p->commit($this->wt, 'export.php');
    $this->gh = Sandbox::tmp();
    $this->on = ['KANBAN_UPSTREAM' => 'true', 'FAKE_GH_DIR' => $this->gh, 'PATH' => Sandbox::package().'/tests/Support/FakeGh:'.getenv('PATH')];
    $this->off = ['KANBAN_UPSTREAM' => false];
    $this->calls = fn () => is_file($this->gh.'/calls.jsonl')
        ? array_map(fn (string $l) => json_decode($l, true), file($this->gh.'/calls.jsonl', FILE_IGNORE_NEW_LINES))
        : [];
    $this->report = function (array $upstream, array $env = []) {
        return $this->p->in($this->wt, ['report', $this->id, '--status=review', '--summary=Done', ...array_map(fn ($u) => "--upstream={$u}", $upstream)], $env + $this->off);
    };
    $this->apply = function () {
        $this->p->hook('subagent-stop', $this->p->payload('subagent-stop', ['cwd' => $this->wt]));
    };
    $this->findings = fn () => array_values(array_filter($this->p->card($this->id)['log'], fn (array $e) => $e['event'] === 'upstream'));
});

it('refuses a finding that names the project or the machine, without echoing it', function (string $text, string $kind) {
    $text = strtr($text, ['{id}' => $this->id, '{host}' => (string) gethostname()]);
    $process = ($this->report)(["Stack wait gives up early — {$text}"]);

    expect($process->getExitCode())->toBe(2)
        ->and($process->getErrorOutput())->toContain("--upstream names {$kind}")->toContain('restate it in generic terms')
        ->and($process->getErrorOutput())->not->toContain($text)
        ->and($this->p->runtime("staged/{$this->id}.report.json"))->not->toBeFile();
})->with([
    'board key' => ['seen on the LEDGER board', 'the board key'],
    'card id' => ['while working on {id}', 'a card id'],
    'APP_NAME' => ['the acme-notes app', 'APP_NAME'],
    'APP_URL host' => ['at notes.test', 'the APP_URL host'],
    'composer name' => ['in the orbit package', 'the composer name'],
    'git remote owner' => ['under northwind', 'the git remote'],
    'git remote repo' => ['in ledger app', 'the git remote'],
    'checkout directory' => ['cd orchard first', 'the checkout directory'],
    'hostname' => ['on {host}', 'the hostname'],
    'git user' => ['as Test User', 'the git user'],
    'email' => ['mail dev@example.org', 'an email address'],
    'home path' => ['under /home/dev/code', 'a home path'],
    'macOS home path' => ['under /Users/dev/code', 'a home path'],
    'tilde path' => ['in ~/.config', 'a home path'],
    'LOCAL_APP_URL address' => ['at 198.18.0.7', 'an IPv4 address outside RFC 5737'],
    'any other address' => ['via 198.19.255.1', 'an IPv4 address outside RFC 5737'],
]);

it('passes framework and package vocabulary, short words, documentation and loopback addresses', function () {
    $text = 'Laravel 13 Horizon restarts — app at 192.0.2.10, 198.51.100.4, 203.0.113.9 and 127.0.0.1 with laravel/framework, vite and laravel-house; ACME-style ids';
    $process = ($this->report)([$text]);

    expect($process->getExitCode())->toBe(0)
        ->and($process->getOutput())->toContain(', 1 upstream');
    $staged = json_decode(file_get_contents($this->p->runtime("staged/{$this->id}.report.json")), true);
    expect($staged['upstream'])->toBe([['title' => 'Laravel 13 Horizon restarts', 'body' => 'app at 192.0.2.10, 198.51.100.4, 203.0.113.9 and 127.0.0.1 with laravel/framework, vite and laravel-house; ACME-style ids']]);
});

it('refuses nothing for a stock Laravel name', function () {
    file_put_contents($this->p->main.'/.env', "APP_NAME=Laravel\nAPP_URL=http://localhost\n");
    file_put_contents($this->p->main.'/composer.json', json_encode(['name' => 'laravel/laravel']));

    expect(($this->report)(['Laravel boots twice — the laravel app of laravel/laravel in localhost'])->getExitCode())->toBe(0);
});

it('applies findings as upstream log entries and never as cards', function () {
    $cards = count(glob($this->p->main.'/docs/kanban/*/*/*.json'));
    ($this->report)(['Stack wait ignores the health path — it polls / instead', 'Gates run twice'])->mustRun();
    ($this->apply)();

    $findings = array_column(($this->findings)(), null, 'title');
    expect($findings)->toHaveCount(2)
        ->and($findings['Stack wait ignores the health path'])->toMatchArray(['event' => 'upstream', 'by' => 'worker', 'body' => 'it polls / instead'])
        ->and($findings['Gates run twice'])->not->toHaveKey('body')
        ->and(count(glob($this->p->main.'/docs/kanban/*/*/*.json')))->toBe($cards)
        ->and($this->p->card($this->id)['stage'])->toBe('review');

    $verdict = $this->p->in($this->wt, ['verdict', $this->id, 'reject', '--check=1:fail:no', '--check=2:pass:ok', '--upstream=Verdict needs a retry flag'], $this->off);
    expect($verdict->getExitCode())->toBe(0)->and($verdict->getOutput())->toContain(', 1 upstream');
    $this->p->hook('subagent-stop', $this->p->payload('subagent-stop', ['cwd' => $this->wt, 'type' => 'kanban-evaluator', 'agent' => 'e5e5c0ffee']));
    expect(array_column(($this->findings)(), 'title'))->toHaveCount(3)->toContain('Verdict needs a retry flag')
        ->and(lastOf(($this->findings)())['by'])->toBe('evaluator');
});

it('names the option in the agent context and the count in the brief only while filing is on', function () {
    ($this->report)(['Gates run twice'])->mustRun();
    ($this->apply)();

    expect($this->p->in($this->wt, ['context'], $this->off)->getOutput())->not->toContain('--upstream')
        ->and($this->p->sandbox->ok('status', $this->off))->not->toContain('upstream:')
        ->and($this->p->in($this->wt, ['context'], $this->on)->getOutput())->toContain('add --upstream="Title — body" in generic terms')
        ->and($this->p->sandbox->ok('status', $this->on))->toContain('upstream: 1 pending (`kanban upstream`)');
});

it('lists pending findings and files one through gh with the label and the package version', function () {
    ($this->report)(['Stack wait ignores the health path — it polls / instead'])->mustRun();
    ($this->apply)();
    $finding = ($this->findings)()[0]['id'];
    $ref = "{$this->id}:{$finding}";

    expect($this->p->sandbox->ok('upstream', $this->off))->toBe("{$ref} Stack wait ignores the health path\n  it polls / instead\n"
        ."filing is off (KANBAN_UPSTREAM): list them for the owner, or `kanban upstream dismiss ID:logid --reason=…`\n");

    $off = $this->p->sandbox->kanban(['upstream', 'file', $ref], $this->off);
    expect($off->getExitCode())->toBe(3)->and($off->getErrorOutput())->toContain('filing upstream is off');

    $hits = $this->p->sandbox->kanban(['upstream', 'file', $ref], ['FAKE_GH_HITS' => '[{"number":7,"title":"Stack wait polls the root","url":"https://github.com/petar-spasic/laravel-house/issues/7"}]'] + $this->on);
    expect($hits->getExitCode())->toBe(3)
        ->and($hits->getOutput())->toBe("open #7 Stack wait polls the root https://github.com/petar-spasic/laravel-house/issues/7\n")
        ->and($hits->getErrorOutput())->toContain('--comment=N adds the finding to one, --new files it anyway')
        ->and(($this->calls)())->toBe([
            ['auth', 'status'],
            ['issue', 'list', '--repo', 'petar-spasic/laravel-house', '--state', 'open', '--search', 'stack wait ignores health path', '--json', 'number,title,url', '--limit', '5'],
        ]);

    $filed = $this->p->sandbox->kanban(['upstream', 'file', $ref], $this->on);
    $version = InstalledVersions::getPrettyVersion('petar-spasic/laravel-house');
    expect($filed->getExitCode())->toBe(0)
        ->and($filed->getOutput())->toContain('filed #101 https://github.com/petar-spasic/laravel-house/issues/101')
        ->and(lastOf(($this->calls)()))->toBe(['issue', 'create', '--repo', 'petar-spasic/laravel-house', '--title', 'Stack wait ignores the health path',
            '--body', "it polls / instead\n\n---\nFlagged by an agent; laravel-house {$version}".(str_contains((string) $version, (string) InstalledVersions::getReference('petar-spasic/laravel-house')) ? '' : ' ('.substr((string) InstalledVersions::getReference('petar-spasic/laravel-house'), 0, 7).')'),
            '--label', 'agent-finding'])
        ->and(lastOf($this->p->card($this->id)['log']))->toMatchArray(['event' => 'upstream_filed', 'finding' => $finding, 'issue' => 101, 'url' => 'https://github.com/petar-spasic/laravel-house/issues/101'])
        ->and($this->p->sandbox->ok('upstream', $this->on))->toBe("no pending upstream findings\n");

    $again = $this->p->sandbox->kanban(['upstream', 'file', $ref, '--new'], $this->on);
    expect($again->getExitCode())->toBe(3)->and($again->getErrorOutput())->toContain('is already filed or dismissed');
});

it('comments on an open issue, or files anyway, when asked', function () {
    ($this->report)(['Gates run twice', 'Brief hides hubs'])->mustRun();
    ($this->apply)();
    ['Gates run twice' => $first, 'Brief hides hubs' => $second] = array_column(($this->findings)(), 'id', 'title');

    $both = $this->p->sandbox->kanban(['upstream', 'file', "{$this->id}:{$first}", '--new', '--comment=7'], $this->on);
    expect($both->getExitCode())->toBe(2);

    $this->p->sandbox->ok(['upstream', 'file', "{$this->id}:{$first}", '--comment=7'], $this->on);
    expect(lastOf(($this->calls)()))->toMatchArray([0 => 'issue', 1 => 'comment', 2 => '7', 3 => '--repo', 4 => 'petar-spasic/laravel-house', 5 => '--body'])
        ->and(lastOf(($this->calls)())[6])->toStartWith("**Gates run twice**\n\n---\nFlagged by an agent; laravel-house ")
        ->and(lastOf($this->p->card($this->id)['log']))->toMatchArray(['event' => 'upstream_filed', 'finding' => $first, 'issue' => 7]);

    $this->p->sandbox->ok(['upstream', 'file', "{$this->id}:{$second}", '--new'], $this->on);
    expect(array_map(fn (array $c) => $c[1] ?? null, ($this->calls)()))->not->toContain('list')
        ->and(lastOf(($this->calls)())[1])->toBe('create');
});

it('refuses to file when gh is missing or signed out, or the text names the project here', function () {
    ($this->report)(['Board sync stalls — after a rebase'])->mustRun();
    ($this->apply)();
    $ref = $this->id.':'.($this->findings)()[0]['id'];

    $bin = Sandbox::tmp();
    symlink((new ExecutableFinder)->find('git'), $bin.'/git');
    $missing = $this->p->sandbox->kanban(['upstream', 'file', $ref], ['PATH' => $bin] + $this->on);
    expect($missing->getExitCode())->toBe(3)->and($missing->getErrorOutput())->toContain('gh is not installed');

    $signedOut = $this->p->sandbox->kanban(['upstream', 'file', $ref], ['FAKE_GH_AUTH' => 'fail'] + $this->on);
    expect($signedOut->getExitCode())->toBe(3)->and($signedOut->getErrorOutput())->toContain('gh is not signed in');

    file_put_contents($this->p->main.'/.env', "APP_NAME=\"Board Sync\"\n");
    $named = $this->p->sandbox->kanban(['upstream', 'file', $ref], $this->on);
    expect($named->getExitCode())->toBe(2)
        ->and($named->getErrorOutput())->toContain('the finding names APP_NAME')
        ->and(array_column(($this->calls)(), 1))->not->toContain('create');
});

it('dismisses a finding with a reason', function () {
    ($this->report)(['Gates run twice'])->mustRun();
    ($this->apply)();
    $ref = $this->id.':'.($this->findings)()[0]['id'];

    expect($this->p->sandbox->kanban(['upstream', 'dismiss', $ref], $this->off)->getExitCode())->toBe(2)
        ->and($this->p->sandbox->kanban(['upstream', 'dismiss', $this->id.':ZZZZZZZZ', '--reason=x'], $this->off)->getExitCode())->toBe(4);

    expect($this->p->sandbox->ok(['upstream', 'dismiss', $ref, '--reason=a project choice'], $this->off))->toBe("dismissed {$ref}\n")
        ->and(lastOf($this->p->card($this->id)['log']))->toMatchArray(['event' => 'upstream_dismissed', 'finding' => ($this->findings)()[0]['id'], 'reason' => 'a project choice'])
        ->and($this->p->sandbox->ok('upstream', $this->off))->toBe("no pending upstream findings\n");

    $worker = $this->p->in($this->wt, ['upstream'], ['KANBAN_SESSION' => 'x'] + $this->off);
    expect($worker->getExitCode())->toBe(3);
});

it('doctor checks gh while filing is on', function () {
    $doctor = fn (array $env) => $this->p->sandbox->kanban(['doctor'], $env + ['KANBAN_STATE_DIR' => Sandbox::tmp()])->getOutput();

    expect($doctor($this->off))->not->toContain('gh ')
        ->and($doctor($this->on))->toContain('ok gh signed in: `kanban upstream file` files findings on petar-spasic/laravel-house')
        ->and($doctor(['FAKE_GH_AUTH' => 'fail'] + $this->on))->toContain('warn gh is not signed in (`gh auth login`)');
});
