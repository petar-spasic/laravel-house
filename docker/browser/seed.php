<?php

/*
 * Seeds a sandbox checkout with an invented project (Acme Notes) and serves its /kanban UI on 0.0.0.0:<port> with the
 * package's own Content-Security-Policy in force. Writes {root, ids} to <json file> first.
 * The server replaces this process (exec), so the sandbox's shutdown cleanup never runs while it is served.
 *
 * With --rich the board also holds every state the UI draws (agents working, stale and stopped, review and done cards,
 * blocked and waiting cards, an open question, a card three others wait on, long titles, many labels, a stack link, a
 * merge queue with a card being merged, one queued and one waiting for a red main, two epics and a second board) and
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
use PetarSpasic\LaravelHouse\Kanban\KanbanServiceProvider;
use PetarSpasic\LaravelHouse\Kanban\Policy\Creation;
use PetarSpasic\LaravelHouse\Kanban\Policy\Transitions;
use PetarSpasic\LaravelHouse\Kanban\Protocol\MergeState;
use PetarSpasic\LaravelHouse\Kanban\Store\Actor;
use PetarSpasic\LaravelHouse\Kanban\Store\BoardRef;
use PetarSpasic\LaravelHouse\Kanban\Store\Store;
use PetarSpasic\LaravelHouse\Tests\Support\CodeSandbox;
use PetarSpasic\LaravelHouse\Tests\Support\Origin;
use PetarSpasic\LaravelHouse\Tests\Support\Sandbox;
use PetarSpasic\LaravelHouse\Tests\Support\UiSandbox;

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

if ($rich || $perf) {
    UiSandbox::boot($s->root);
    $store = app(Store::class);
    $transitions = new Transitions($store);
    $main = new Actor('main', 's1');
    $host = CodeSandbox::lanHost();
    $ages = [];

    $make = function (string $title, array $input = [], string $board = 'work') use ($store): string {
        $ref = BoardRef::parse($board);
        $fields = (new Creation)->fields($store->snapshot(), $ref, ['title' => $title] + $input);

        return $store->create($ref, $fields, Actor::owner())->id();
    };
    $base = trim($s->git('rev-parse', 'refs/heads/main'));
    // a card reaches ready through its plan: created into planning, then given one
    $ready = function (string $title, array $input = [], string $board = 'work', ?string $plan = null) use ($make, $store, $transitions, $base): string {
        $id = $make($title, ['body' => 'Build it', 'accept' => ['It works'], 'stage' => 'planning'] + $input, $board);
        $transitions->plan($id, $plan ?? Sandbox::planFor(array_column($store->card($id)->acceptance(), 'id')), $base, Actor::owner());

        return $id;
    };
}

if ($perf) {
    for ($i = 1; $i <= 300; $i++) {
        $make("Chore number {$i} of the long backlog", ['type' => 'chore']);
    }
}

if ($rich) {
    $agent = function (string $name, string $card, int $boundAgo, int $beatAgo = 0, bool $stopped = false, string $type = 'kanban-worker') use ($s): void {
        @mkdir($s->root.'/.git/laravel-house/agents', 0775, true);
        $file = $s->root."/.git/laravel-house/agents/{$name}.json";
        file_put_contents($file, json_encode(['agent_id' => $name, 'agent_type' => $type, 'card' => $card, 'worktree' => null,
            'bound_at' => gmdate('Y-m-d\TH:i:s.000+00:00', time() - $boundAgo), 'stopped_at' => $stopped ? gmdate('Y-m-d\TH:i:s.000+00:00') : null, 'stop_blocks' => 0]));
        touch($file, time() - $beatAgo);
    };
    $stack = fn (string $name, int $port) => ['branch' => 'card/'.$name, 'stack' => ['project' => "acme-wt-{$name}", 'slot' => 1, 'ports' => [], 'url' => "http://{$host}:{$port}"]];

    // in flight: an agent working with a stack, one gone stale, one review card whose agent stopped
    $ids['w1'] = $ready('Wire billing webhooks to the ledger', ['priority' => 'high', 'labels' => ['area:billing', 'backend']]);
    $transitions->start($ids['w1'], $main, null, $stack('billing', 21010));
    $agent('worker-1', $ids['w1'], 240);
    $ids['w2'] = $ready('Cache the notebook index between requests', ['labels' => ['area:search', 'perf']]);
    $transitions->start($ids['w2'], $main, null, $stack('cache', 21020));
    $agent('worker-2', $ids['w2'], 3000, 2400);
    $ids['r1'] = $ready('Paginate the activity feed', ['labels' => ['area:feed', 'frontend']]);
    $transitions->start($ids['r1'], $main, null, $stack('feed', 21030));
    $transitions->apply($ids['r1'], $main);
    $agent('worker-3', $ids['r1'], 5400, 60, true);

    // the merge queue: a card being merged, the fix for a red main queued, and one that waits for that fix
    foreach (['m_merging' => ['Import notes from a ZIP file', ['area:sync'], 3600], 'm_queued' => ['main red: php artisan test', ['main-red', 'area:auth'], 3000], 'm_held' => ['Archive old notebooks', ['area:export'], 2400]] as $key => [$title, $labels, $ago]) {
        $ids[$key] = $ready($title, ['labels' => $labels]);
        // areas the board already colours (at most ten), so the start goes past their cards in flight
        $transitions->start($ids[$key], $main, null, ['branch' => 'card/'.str_replace('_', '-', $key), 'stack' => null], force: true);
        $transitions->apply($ids[$key], $main);
        $store->update($ids[$key], function (array $data) use ($ago, $key) {
            $data['work']['approved'] = ['head' => str_repeat('a', 40), 'at' => gmdate('Y-m-d\TH:i:s.000+00:00', time() - $ago)];
            if ($key === 'm_queued') {
                // a main-red card is the fix only with its command's criterion
                $data['acceptance'][0]['text'] = '`php artisan test` passes on main';
            }

            return $data;
        }, $main);
    }
    $store->update($ids['m_held'], function (array $data) use ($ids, $base) {
        $data['log'][] = MergeState::entry('main', ['red' => $ids['m_queued'], 'command' => 'php artisan test', 'base' => $base]);

        return $data;
    }, $main);
    $store->lease(fn (?array $old) => [['id' => '9f2c41d07a8b3e65', 'card' => $ids['m_merging'], 'by' => 'ana@host-a', 'who' => 'Ana',
        'since' => gmdate('Y-m-d\TH:i:s.000+00:00', time() - 600), 'beat' => gmdate('Y-m-d\TH:i:s.000+00:00', time() - 60)], [], "merge lease {$ids['m_merging']} taken"], $main);

    // planning: a card waiting for its planner, one a planner works on, and a planned one in ready
    $ids['to_plan'] = $make('Tag notes from the editor', ['body' => 'Tags are typed in the editor and saved with the note.', 'accept' => ['A tag typed in the editor is saved'], 'stage' => 'planning', 'labels' => ['area:sync']]);
    $ids['planning'] = $make('Share a notebook read-only', ['body' => 'A link that opens the notebook without an account.', 'accept' => ['The link opens the notebook', 'It cannot be edited'], 'stage' => 'planning', 'labels' => ['area:print']]);
    $transitions->start($ids['planning'], $main, null, $stack('share', 21040));
    $agent('planner-1', $ids['planning'], 420, 0, false, 'kanban-planner');
    $ids['planned'] = $ready('Show the history of a note', ['labels' => ['area:import'], 'accept' => ['Each saved version is listed', 'A version opens read-only']], 'work', <<<'MD'
        ## Goal
        The note page lists every saved version; a version opens read-only.

        ## Files
        - read `README.md` — how the app is laid out
        - create `app/Http/Controllers/NoteVersionController.php` — invokable, shaped like the note controller
        - create `tests/Feature/NoteVersionsTest.php` — criteria 1 and 2

        ## Steps
        1. Add `Route::get('/notes/{note}/versions', NoteVersionController::class)->name('notes.versions')`. Check: `php artisan route:list --name=notes.versions` → 1 route.
        2. List the versions newest first, 20 a page. Check: `php artisan test --compact --filter=NoteVersions`

        ## Criteria
        - 1: `tests/Feature/NoteVersionsTest.php` `it lists each saved version`: 3 saves → 3 rows
        - 2: `tests/Feature/NoteVersionsTest.php` `it opens a version read-only`: no form on the page

        ## Traps
        - `Note::versions()` includes drafts: filter on `saved_at`
        MD);

    // attention states and stress shapes
    $ids['blocked'] = $make('Rotate the signing keys before the audit', ['priority' => 'urgent', 'type' => 'bug']);
    $s->ok(['set', $ids['blocked'], 'blocked=Waiting for the security team to approve the new key length and the rollout window before anything else can move']);
    $s->ok(['epic', 'exports', 'Exports', '--goal=Notebooks leave the app in the formats people use', '--done-when=CSV, Markdown and nightly exports ship']);
    $s->ok(['epic', 'billing', 'Billing']);
    $ids['schema'] = $make('Define the export schema', ['priority' => 'high', 'labels' => ['area:export'], 'accept' => ['Schema documented', 'Sample export attached']]);
    $ids['job'] = $make('Build the export job', ['depends' => [$ids['schema']], 'epic' => 'exports']);
    $ids['csv'] = $make('Export a notebook as CSV', ['depends' => [$ids['schema']], 'epic' => 'exports']);
    $ids['md'] = $make('Export a notebook as Markdown', ['depends' => [$ids['schema']], 'epic' => 'exports']);
    $s->ok(['set', $ids['schema'], 'epic=exports']);
    $s->ok(['set', $ids['w1'], 'epic=billing']);
    $ids['question'] = $make('Print notes as PDF', ['labels' => ['area:print'], 'body' => "## Goal\n\nA printable PDF of one note.\n\n## Open question\n\nRender with a headless browser, or with a PDF library on the server?"]);
    $s->ok(['set', $ids['question'], 'blocked=question: headless browser or a server-side PDF library?']);
    $ids['nightly'] = $make('Schedule nightly exports', ['depends' => [$ids['job']], 'labels' => ['ops']]);
    $ids['long'] = $make('Migrate notebook sharing from the legacy queue worker to the new event pipeline without any downtime for users', ['type' => 'spike']);
    $ids['labels'] = $make('Clean up the sync module', ['labels' => ['backend', 'perf', 'needs-design', 'tech-debt', 'area:sync'], 'accept' => ['No dead code left']]);
    $ids['low'] = $make('Tidy the settings copy', ['priority' => 'low', 'labels' => ['ui']]);
    $ids['cand'] = $make('Add CSV import', ['body' => 'Build it', 'accept' => ['It works'], 'priority' => 'low', 'labels' => ['area:import']]);
    $ages[$ids['nightly']] = 9 * 86400;
    $ages[$ids['long']] = 3 * 86400;
    $ages[$ids['low']] = 26 * 3600;

    // shipped work: the done lane caps at 20, so 23 shows the older link
    for ($i = 1; $i <= 23; $i++) {
        $id = $ready("Shipped change {$i}", ['labels' => $i % 4 === 0 ? ['area:changes', 'backend'] : ['area:changes']]);
        $transitions->start($id, $main, null, ['branch' => "card/shipped-{$i}", 'stack' => null]);
        $transitions->apply($id, $main);
        $transitions->finish($id, sprintf('%07x', 0xABC000 + $i), $main);
        $ages[$id] = $i * 5 * 3600;
    }
    // the blocked card also depends on something already shipped, so its panel shows the banner and a dependency (nothing waits on it)
    $s->ok(['set', $ids['blocked'], 'depends_on=+'.$id]);

    // a second board, so the switcher lists two
    $s->ok(['board', 'infra', 'Infra']);
    foreach (['Move the queue to Redis', 'Rotate database credentials', 'Add a staging environment'] as $title) {
        $make($title, [], 'infra');
    }
    $ready('Enable nightly backups', ['labels' => ['area:backups']], 'infra');

    // backdate: every timestamp of a card moves back by its age, keeping the file's formatting
    foreach ($ages as $id => $seconds) {
        foreach (glob($s->root."/docs/kanban/*/{$id}.json") as $file) {
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
