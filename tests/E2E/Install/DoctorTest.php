<?php

use PetarSpasic\LaravelHouse\Kanban\Console\Install\Steps;
use PetarSpasic\LaravelHouse\Tests\Support\Sandbox;
use Symfony\Component\Process\Process;

beforeEach(fn () => Steps::register(app()));

it('passes on a correctly wired project', function () {
    $sandbox = doctorSandbox();

    $process = doctor($sandbox);

    expect($process->getExitCode())->toBe(0)
        ->and($process->getOutput())
        ->toContain("ok board attached at docs/kanban\n")
        ->toContain("ok merge driver {$sandbox->root}/vendor/bin/kanban\n")
        ->toContain("ok core.hooksPath vendor/petar-spasic/laravel-house/githooks\n")
        ->toContain("ok .claude/settings.json hooks\n")
        ->toContain("ok vendor/petar-spasic/laravel-house/bin/kanban-guard executable\n")
        ->toContain("ok .claude/agents/kanban-worker.md\n")
        ->toContain("ok CLAUDE.md kanban block\n")
        ->toContain("ok .gitignore /docs/kanban/ /.claude/worktrees /.claude/settings.local.json\n")
        ->toContain("ok runtime .git/laravel-house writable\n")
        ->toContain("ok no orphan worktrees or stack slots\n")
        ->toContain("ok docker-compose.local.yml is worktree-safe\n")
        ->toContain("ok COMPOSE_PROJECT_NAME=app-local in .env\n")
        ->toContain("ok docker address pools: 254 free networks\n")
        ->not->toContain('fail ')
        ->not->toContain('warn ');
});

it('fails each compose problem and the missing project name, and warns about a phpunit.xml database address', function () {
    $sandbox = doctorSandbox('compose-bad.yml', "APP_NAME=app\n");
    file_put_contents($sandbox->root.'/phpunit.xml', '<phpunit><php><env name="DB_HOST" value="127.0.0.1"/><env name="DB_PORT" value="5435"/></php></phpunit>');

    $process = doctor($sandbox, env: ['FAKE_POOLS' => '[{"Base":"198.18.0.0/15","Size":16}]', 'FAKE_SUBNETS' => '198.18.0.0/16']);

    $fixedPort = fn (string $port, string $service, int $line) => "fail docker-compose.local.yml: fixed host port {$port} on service {$service} (line {$line}): one port for every stack; publish it from a stack.ports variable\n";
    expect($process->getExitCode())->toBe(1)
        ->and($process->getOutput())
        ->toContain("fail docker-compose.local.yml: container_name on service app (line 5): one fixed name for every stack; remove it\n")
        ->toContain("fail docker-compose.local.yml: container_name on service postgres (line 13): one fixed name for every stack; remove it\n")
        ->toContain($fixedPort('8011', 'app', 7))
        ->toContain($fixedPort('5435', 'postgres', 16))
        ->toContain($fixedPort('8025', 'mailpit', 21))
        ->not->toContain('host port variable VITE_PORT')
        ->toContain("fail docker-compose.local.yml: volume postgres_local has name: (line 25): shared by every stack; remove it, or derive it from \${COMPOSE_PROJECT_NAME}\n")
        ->toContain("fail docker-compose.local.yml: network app has name: (line 29): shared by every stack; remove it, or derive it from \${COMPOSE_PROJECT_NAME}\n")
        ->toContain("fail docker-compose.local.yml: image: on built service app (line 4): every stack would tag the same image; remove it\n")
        ->toContain('fail docker-compose.local.yml: top-level name: must be "${COMPOSE_PROJECT_NAME:?…}"')
        ->toContain("fail COMPOSE_PROJECT_NAME missing from .env: the main stack has no project name (add e.g. COMPOSE_PROJECT_NAME=main-local)\n")
        ->toContain("warn phpunit.xml hardcodes DB_HOST, DB_PORT: tests in a worktree would hit the main database; remove them\n")
        ->toContain("warn docker address pools: 1 free networks (< 12, stack.max_stacks) in 198.18.0.0/15 (/16 networks): widen default-address-pools in /etc/docker/daemon.json (README)\n")
        ->not->toContain('ok docker-compose.local.yml')
        ->and(substr_count($process->getOutput(), 'fixed host port'))->toBe(3);
});

it('warns about fixed external volume names in the compose files layered over the local one', function () {
    $sandbox = doctorSandbox();
    copy(__DIR__.'/fixtures/compose-overlay.yml', $sandbox->root.'/docker-compose.prod-local.yml');

    $process = doctor($sandbox);

    $fixed = fn (string $key, string $name, int $line) => "warn docker-compose.prod-local.yml: external volume {$key} has the fixed name {$name} (line {$line}): the local stack's volumes are named <project>_<volume>; use \"\${COMPOSE_PROJECT_NAME}_<volume>\" or a variable\n";
    expect($process->getExitCode())->toBe(0)
        ->and($process->getOutput())
        ->toContain("ok docker-compose.local.yml is worktree-safe\n")
        ->toContain($fixed('db', 'app-postgres-local', 7))
        ->toContain($fixed('cache', 'cache', 10))
        ->and(substr_count($process->getOutput(), 'external volume'))->toBe(2);
});

it('warns when docker is unreachable', function () {
    $process = doctor(doctorSandbox(), env: ['FAKE_DOCKER_DOWN' => '1']);

    expect($process->getExitCode())->toBe(0)
        ->and($process->getOutput())->toContain("warn docker not reachable: address pool headroom not checked\n");
});

it('reports missing wiring and repairs it with --fix', function () {
    $sandbox = doctorSandbox();
    unlink($sandbox->root.'/.claude/agents/kanban-worker.md');
    unlink($sandbox->root.'/.claude/settings.json');
    file_put_contents($sandbox->root.'/.gitignore', "/vendor/\n/docs/kanban/\n");
    $sandbox->git('config', '--unset', 'merge.kanban.driver');

    $broken = doctor($sandbox);

    expect($broken->getExitCode())->toBe(1)
        ->and($broken->getOutput())
        ->toContain("fail merge driver not configured (run `vendor/bin/kanban attach`)\n")
        ->toContain("fail .claude/settings.json missing (run `vendor/bin/kanban doctor --fix`)\n")
        ->toContain("fail .claude/agents/kanban-worker.md missing (run `vendor/bin/kanban doctor --fix`)\n")
        ->toContain("fail .gitignore lacks /.claude/worktrees /.claude/settings.local.json (run `vendor/bin/kanban doctor --fix`)\n");

    $fixed = doctor($sandbox, ['--fix']);

    expect($fixed->getExitCode())->toBe(0)
        ->and($fixed->getOutput())
        ->toContain("fix: board attached at docs/kanban\n")
        ->toContain('fix: created .claude/settings.json: hooks.SessionStart')
        ->toContain("fix: wrote .claude/agents/kanban-worker.md\n")
        ->toContain("fix: .gitignore += /.claude/worktrees /.claude/settings.local.json\n")
        ->not->toContain('fail ')
        ->toEndWith("ok docker address pools: 254 free networks\nnext: restart Claude Code (agents and hooks load at session start); then `vendor/bin/kanban lease --takeover` if an old session holds the lease\n")
        ->and(doctor($sandbox)->getOutput())->not->toContain('restart Claude Code');
});

it('rejects Co-Authored-By trailers by default and allows them once githooks.reject_co_authored is off', function () {
    $sandbox = doctorSandbox();
    $commit = fn () => (new Process(['git', 'commit', '-q', '--allow-empty', '-m', "KEY-1: x\n\nCo-Authored-By: A <a@example.com>"], $sandbox->root))->run();

    expect($commit())->toBe(1)
        ->and(doctor($sandbox)->getOutput())->toContain("ok commit-msg rejects Co-Authored-By trailers\n");

    @mkdir($sandbox->root.'/config', 0775, true);
    file_put_contents($sandbox->root.'/config/kanban.php', "<?php return ['githooks' => ['reject_co_authored' => false]];\n");
    expect(doctor($sandbox)->getOutput())
        ->toContain("warn commit-msg rejects Co-Authored-By trailers, githooks.reject_co_authored says otherwise (run `vendor/bin/kanban attach`)\n");

    expect(doctor($sandbox, ['--fix'])->getOutput())->toContain("fix: kanban.rejectCoAuthored: false\n")
        ->toContain("ok commit-msg allows Co-Authored-By trailers\n")
        ->and($commit())->toBe(0);
});

it('warns about orphan worktrees and stack slots whose worktree is gone', function () {
    $sandbox = doctorSandbox();
    $id = $sandbox->readyCard('Left behind');
    mkdir($sandbox->root.'/.claude/worktrees/'.strtolower($id), 0775, true);
    $state = Sandbox::tmp();
    file_put_contents($state.'/stacks.json', json_encode(['version' => 1, 'pool' => ['base' => 21000, 'block' => 10, 'first' => 1, 'last' => 99], 'stacks' => [
        '3' => ['slot' => 3, 'project' => 'main-wt-gone', 'repo' => $sandbox->root, 'worktree' => $sandbox->root.'/.claude/worktrees/gone', 'branch' => null, 'card' => null, 'ports' => [], 'created_at' => ''],
    ]]));

    $process = doctor($sandbox, env: ['KANBAN_STATE_DIR' => $state]);

    expect($process->getExitCode())->toBe(0)
        ->and($process->getOutput())
        ->toContain('warn orphan worktree .claude/worktrees/'.strtolower($id).": {$id} is ready")
        ->toContain("warn stack slot 3 (main-wt-gone): worktree {$sandbox->root}/.claude/worktrees/gone is gone (`kanban stack gc`)\n");
});

it('sees fixed ports behind comments and every fixed name in flow-style mappings', function () {
    $sandbox = doctorSandbox('compose-flow.yml');

    $process = doctor($sandbox);

    $output = $process->getOutput();
    expect($process->getExitCode())->toBe(1)
        ->and($output)
        ->toContain('fail docker-compose.local.yml: fixed host port 8090 on service app (line 7)')
        ->toContain('fail docker-compose.local.yml: container_name on service mail (line 8)')
        ->toContain('fail docker-compose.local.yml: fixed host port 8095 on service mail (line 8)')
        ->toContain('fail docker-compose.local.yml: fixed host port 8100 on service web (line 12)')
        ->toContain('fail docker-compose.local.yml: volume pg has name: (line 15)')
        ->toContain('fail docker-compose.local.yml: network edge has name: (line 18)')
        ->not->toContain('ok docker-compose.local.yml');
});

it('accepts a checkout whose path holds an apostrophe', function () {
    $sandbox = doctorSandbox(name: "Client's app");

    $process = doctor($sandbox);
    $fixed = doctor($sandbox, ['--fix']);

    expect($process->getOutput())->toContain('ok merge driver')
        ->and($process->getOutput())->not->toContain('fail merge driver')
        ->and($process->getExitCode())->toBe(0)
        ->and($fixed->getExitCode())->toBe(0);
});

it('reads nested variable defaults and quoted hashes, and gives each extra host port variable an offset of the block', function () {
    $sandbox = doctorSandbox('compose-nested.yml');

    $process = doctor($sandbox);

    expect($process->getExitCode())->toBe(0)
        ->and($process->getOutput())->toContain("ok docker-compose.local.yml is worktree-safe\n")
        ->not->toContain('host port variable')
        ->not->toContain('fixed host port');

    $config = '<?php $c = require '.var_export(Sandbox::package().'/config/kanban.php', true).'; $c[\'stack\'][\'pool\'][\'block\'] = 4; return $c;';
    @mkdir($sandbox->root.'/config');
    file_put_contents($sandbox->root.'/config/kanban.php', $config);
    $small = doctor($sandbox);

    expect($small->getExitCode())->toBe(1)
        ->and($small->getOutput())
        ->toContain('fail docker-compose.local.yml: host port variable STRAY_PORT on service app (line 12): no offset left for it in a stack.pool block')
        ->not->toContain('host port variable MAIL_PORT');
});

it('accepts a host port published from a stack.env variable that is built from a stack.ports one', function () {
    $sandbox = doctorSandbox('compose-envport.yml');

    $process = doctor($sandbox);

    expect($process->getExitCode())->toBe(0)
        ->and($process->getOutput())->toContain("ok docker-compose.local.yml is worktree-safe\n")
        ->not->toContain('host port variable');
});

it('warns when the local compose file mounts no worktree at its host path, unless agents\' shells stay on the host', function () {
    $sandbox = doctorSandbox();
    file_put_contents($sandbox->root.'/docker-compose.local.yml', str_replace('      - .:${KANBAN_WORKTREE_PATH:-/app}'."\n", '', file_get_contents($sandbox->root.'/docker-compose.local.yml')));
    $warning = 'warn docker-compose.local.yml lacks - ./:${KANBAN_WORKTREE_PATH:-/app} in the app service\'s volumes';

    expect(doctor($sandbox)->getOutput())->toContain($warning)
        ->and(doctor($sandbox, env: ['KANBAN_AGENT_SHELL' => 'host'])->getOutput())->not->toContain($warning);
});

it('warns when tmp or the checkout runs low on inodes while space looks fine', function () {
    $sandbox = doctorSandbox();
    $fixtures = __DIR__.'/fixtures';

    $low = doctor($sandbox, env: ['PATH' => "{$fixtures}/lowdisk:{$fixtures}:".getenv('PATH')]);
    $fine = doctor($sandbox);

    expect($low->getOutput())->toMatch('/^warn tmp \(.+\) inodes 4% free: /m')
        ->and($low->getOutput())->not->toContain('space 92%')
        ->and($fine->getOutput())->toContain('ok disk space and inodes');
});

it('adds the card-stack lines to a compose file written before them, once, and leaves the rest as it was', function () {
    $sandbox = doctorSandbox('compose-cardless.yml');
    $file = $sandbox->root.'/docker-compose.local.yml';
    expect(file_get_contents($file))->toContain('KANBAN_WORKTREE_PATH');
    // as a project installed before these lines has it
    copy(__DIR__.'/fixtures/compose-cardless.yml', $file);
    $before = file_get_contents($file);

    $warned = doctor($sandbox)->getOutput();
    $fixed = doctor($sandbox, ['--fix'])->getOutput();
    $after = file_get_contents($file);
    $again = doctor($sandbox, ['--fix'])->getOutput();

    expect($warned)->toContain('warn docker-compose.local.yml lacks - ./:${KANBAN_WORKTREE_PATH:-/app}')
        ->toContain('`vendor/bin/kanban doctor --fix` adds them')
        ->and($fixed)->toContain('fix: added to docker-compose.local.yml: the card mount; the ssh command as KANBAN_GIT_SSH_COMMAND and TMPDIR')
        ->toContain('ok docker-compose.local.yml carries the card-stack lines')
        ->and($after)->toBe(str_replace([
            "      - ./:/app\n",
            "      GIT_SSH_COMMAND: ssh -i /app/.git/laravel-house/deploy_key -o IdentitiesOnly=yes -o BatchMode=yes\n",
        ], [
            "      - ./:/app\n      - ./:\${KANBAN_WORKTREE_PATH:-/app}\n",
            "      TMPDIR: \${KANBAN_TMPDIR:-/tmp}\n      GIT_SSH_COMMAND: \${KANBAN_GIT_SSH_COMMAND-ssh -i /app/.git/laravel-house/deploy_key -o IdentitiesOnly=yes -o BatchMode=yes}\n",
        ], $before))
        ->and($again)->not->toContain('fix: added to')
        ->and(file_get_contents($file))->toBe($after);
});
