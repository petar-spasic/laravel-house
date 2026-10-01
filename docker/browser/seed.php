<?php

/*
 * Seeds a sandbox checkout with an invented project (Acme Notes) and serves its /kanban UI on 0.0.0.0:<port> with the
 * package's own Content-Security-Policy in force. Writes {root, ids} to <json file> first.
 * The server replaces this process (exec), so the sandbox's shutdown cleanup never runs while it is served.
 *
 * With --rich the board also holds every state the UI draws (agents working, stale and stopped, review and done cards,
 * blocked and waiting cards, long titles, many labels, a stack link, decisions in every stage, a second epic) and
 * realistic ages; the default seed stays small so the checks' counts do not move.
 *
 * With --perf it holds 300 more chores in the backlog (first-render timing).
 *
 * With --token the UI asks for a token (in the JSON) before it opens.
 *
 * With --team the checkout has an origin and a second clone, "peer", whose owner is Ben; the server runs as Ana with sync on and a
 * pull every 5 seconds, so a check can act as the other person (root and origin path are in the JSON).
 *
 *   php docker/browser/seed.php <port> <json file> [--rich|--perf|--team|--token]
 */

require dirname(__DIR__, 2).'/vendor/autoload.php';

use Illuminate\Contracts\Console\Kernel;
use Orchestra\Testbench\Foundation\Application;
use PetarSpasic\Kanban\KanbanServiceProvider;
use PetarSpasic\Kanban\Policy\Creation;
use PetarSpasic\Kanban\Policy\Transitions;
use PetarSpasic\Kanban\Store\Actor;
use PetarSpasic\Kanban\Store\BoardRef;
use PetarSpasic\Kanban\Store\Store;
use PetarSpasic\Kanban\Tests\Support\CodeSandbox;
use PetarSpasic\Kanban\Tests\Support\Origin;
use PetarSpasic\Kanban\Tests\Support\Sandbox;
use PetarSpasic\Kanban\Tests\Support\UiSandbox;

[, $port, $out] = $argv + [null, '8099', '/tmp/seed.json'];
$rich = in_array('--rich', $argv, true);
$perf = in_array('--perf', $argv, true);
$team = in_array('--team', $argv, true);
$token = in_array('--token', $argv, true);

$app = Application::create(options: ['extra' => ['providers' => [KanbanServiceProvider::class]]]);
$app->make(Kernel::class)->bootstrap();

$s = Sandbox::create('acme-notes');
if ($team) {
    $origin = Origin::create();
    $s->addRemote($origin);
}
$s->install('ACME');
if ($team) {
    $s->git('commit', '-q', '-am', 'Ignore the board worktree');
    $s->git('push', '-q', 'origin', 'main');
}
$ids = [];
$ids['a'] = $s->card('Add login page', ['--priority=high', '--label=area:auth', '--label=frontend', "--body=## Goal\n\nUsers sign in with email and password.\n\n- validate input\n- rate limit", '--accept=Form renders', '--accept=Wrong password shows an error', '--accept=Rate limit after 5 tries']);
$ids['b'] = $s->card('Fix crash on empty cart', ['--type=bug', '--priority=urgent', '--body=Checkout 500s when cart is empty.', '--accept=No 500']);
$ids['c'] = $s->card('Dark mode for settings', ['--priority=low', '--label=ui']);
$ids['d'] = $s->readyCard('Export notes as PDF', ['--label=area:export']);
$ids['e'] = $s->readyCard('Search across notebooks', ['--priority=high']);
$s->ok(['set', $ids['e'], 'blocked=waiting on design']);
$ids['f'] = $s->card('Share a note by link', ["--depends={$ids['d']}"]);
$ids['g'] = $s->card('Abandoned idea');
$s->ok(['move', $ids['g'], 'dropped', '--reason=out of scope']);
for ($i = 1; $i <= 6; $i++) {
    $s->card("Chore number {$i}", ['--type=chore']);
}
$s->card('Workspace per team', ['--why=Teams share notes'], 'project/decisions');
$s->card('Use SQLite for local dev', [], 'project/decisions');

if ($rich || $perf) {
    UiSandbox::boot($s->root);
    $store = app(Store::class);
    $transitions = new Transitions($store);
    $main = new Actor('main', 's1');
    $host = CodeSandbox::lanHost();
    $ages = [];

    $make = function (string $title, array $input = [], string $board = 'project/work') use ($store): string {
        $ref = BoardRef::parse($board);
        $fields = (new Creation)->fields($store->snapshot(), $ref, ['title' => $title] + $input);

        return $store->create($ref, $fields, Actor::owner())->id();
    };
    $ready = fn (string $title, array $input = [], string $board = 'project/work') => $make($title, ['body' => 'Build it', 'accept' => ['It works'], 'stage' => 'ready'] + $input, $board);
}

if ($perf) {
    for ($i = 1; $i <= 300; $i++) {
        $make("Chore number {$i} of the long backlog", ['type' => 'chore']);
    }
}

if ($rich) {
    $agent = function (string $name, string $card, int $boundAgo, int $beatAgo = 0, bool $stopped = false) use ($s): void {
        @mkdir($s->root.'/.git/laravel-kanban/agents', 0775, true);
        $file = $s->root."/.git/laravel-kanban/agents/{$name}.json";
        file_put_contents($file, json_encode(['agent_id' => $name, 'agent_type' => 'kanban-worker', 'card' => $card, 'worktree' => null,
            'bound_at' => gmdate('Y-m-d\TH:i:s.000+00:00', time() - $boundAgo), 'stopped_at' => $stopped ? gmdate('Y-m-d\TH:i:s.000+00:00') : null, 'stop_blocks' => 0]));
        touch($file, time() - $beatAgo);
    };
    $stack = fn (string $name, int $port) => ['branch' => 'card/'.$name, 'stack' => ['project' => "acme-wt-{$name}", 'slot' => 1, 'ports' => [], 'url' => "http://{$host}:{$port}"]];

    // in flight: an agent working with a stack, one gone stale, one review card whose agent stopped
    $ids['w1'] = $ready('Wire billing webhooks to the ledger', ['priority' => 'high', 'labels' => ['backend', 'billing']]);
    $transitions->start($ids['w1'], $main, null, $stack('billing', 21010));
    $agent('worker-1', $ids['w1'], 240);
    $ids['w2'] = $ready('Cache the notebook index between requests', ['labels' => ['perf']]);
    $transitions->start($ids['w2'], $main, null, $stack('cache', 21020));
    $agent('worker-2', $ids['w2'], 3000, 2400);
    $ids['r1'] = $ready('Paginate the activity feed', ['labels' => ['frontend']]);
    $transitions->start($ids['r1'], $main, null, $stack('feed', 21030));
    $transitions->apply($ids['r1'], $main);
    $agent('worker-3', $ids['r1'], 5400, 60, true);

    // attention states and stress shapes
    $ids['blocked'] = $make('Rotate the signing keys before the audit', ['priority' => 'urgent', 'type' => 'bug']);
    $s->ok(['set', $ids['blocked'], 'blocked=Waiting for the security team to approve the new key length and the rollout window before anything else can move']);
    $ids['schema'] = $make('Define the export schema', ['priority' => 'high', 'accept' => ['Schema documented', 'Sample export attached']]);
    $ids['job'] = $make('Build the export job', ['depends' => [$ids['schema']]]);
    $ids['nightly'] = $make('Schedule nightly exports', ['depends' => [$ids['job']], 'labels' => ['ops']]);
    $ids['long'] = $make('Migrate notebook sharing from the legacy queue worker to the new event pipeline without any downtime for users', ['type' => 'spike']);
    $ids['labels'] = $make('Clean up the sync module', ['labels' => ['area:sync', 'backend', 'perf', 'needs-design', 'tech-debt'], 'accept' => ['No dead code left']]);
    $ids['low'] = $make('Tidy the settings copy', ['priority' => 'low', 'labels' => ['ui']]);
    $ids['cand'] = $make('Add CSV import', ['body' => 'Build it', 'accept' => ['It works'], 'priority' => 'low']);
    $ages[$ids['nightly']] = 9 * 86400;
    $ages[$ids['long']] = 3 * 86400;
    $ages[$ids['low']] = 26 * 3600;

    // shipped work: the done lane caps at 20, so 23 shows the older link
    for ($i = 1; $i <= 23; $i++) {
        $id = $ready("Shipped change {$i}", ['labels' => $i % 4 === 0 ? ['backend'] : []]);
        $transitions->start($id, $main, null, ['branch' => "card/shipped-{$i}", 'stack' => null]);
        $transitions->apply($id, $main);
        $transitions->finish($id, sprintf('%07x', 0xABC000 + $i), $main);
        $ages[$id] = $i * 5 * 3600;
    }
    // the blocked card also depends on something already shipped, so its panel shows the banner and a dependency (nothing waits on it)
    $s->ok(['set', $ids['blocked'], 'depends_on=+'.$id]);

    // decisions in every stage, with a supersedes chain
    $old = $make('Use polling to sync notebooks', ['stage' => 'decided', 'why' => 'Simplest thing that works for a first release.'], 'project/decisions');
    $new = $make('Use webhooks to sync notebooks', ['stage' => 'decided', 'why' => 'Polling cost more than it saved once notebooks grew.'], 'project/decisions');
    $s->ok(['set', $new, "supersedes=+{$old}"]);
    $ids['old'] = $old;
    $ids['new'] = $new;
    $ids['decided'] = $make('Store attachments outside the database', ['stage' => 'decided', 'why' => "Blobs made backups slow.\n\n- keep metadata in the database\n- keep files on disk"], 'project/decisions');
    $ids['dropped_decision'] = $make('Build our own markdown parser', [], 'project/decisions');
    $s->ok(['move', $ids['dropped_decision'], 'dropped', '--reason=The library we already have is enough']);
    $ages[$old] = 40 * 86400;

    // a second epic, so the switcher shows groups
    $s->ok(['board', 'platform/infra', 'Infra']);
    foreach (['Move the queue to Redis', 'Rotate database credentials', 'Add a staging environment'] as $title) {
        $make($title, [], 'platform/infra');
    }
    $ready('Enable nightly backups', [], 'platform/infra');

    // backdate: every timestamp of a card moves back by its age, keeping the file's formatting
    foreach ($ages as $id => $seconds) {
        foreach (glob($s->root."/docs/kanban/*/*/{$id}.json") as $file) {
            file_put_contents($file, preg_replace_callback('/\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}[+-]\d{2}:\d{2}/', fn (array $m) => (new DateTimeImmutable($m[0]))->modify("-{$seconds} seconds")->format('Y-m-d\TH:i:s.vP'), (string) file_get_contents($file)));
        }
    }
    $s->boardGit('add', '-A');
    $s->boardGit('commit', '-q', '-m', 'seed: ages');
}

$extra = [];
$env = [];
if ($team) {
    $s->ok('sync');
    $peer = $origin->clone('peer');
    $peer->ok('attach');
    $peer->git('config', 'user.name', 'Ben');
    $extra = ['peer' => $peer->root, 'origin' => $origin->path];
    $env = ['KANBAN_SYNC' => 'on', 'KANBAN_USER' => 'Ana', 'KANBAN_PULL_SECONDS' => '5'];
}
if ($token) {
    $extra = ['token' => 'acme-board-token'];
    $env = ['KANBAN_UI_TOKEN' => $extra['token']];
}

file_put_contents($out, json_encode(['root' => $s->root, 'ids' => $ids] + $extra));
pcntl_exec(PHP_BINARY, ['-S', "0.0.0.0:{$port}", __DIR__.'/router.php'], ['KANBAN_UI_ROOT' => $s->root, 'PATH' => getenv('PATH'), 'HOME' => getenv('HOME')] + $env);
