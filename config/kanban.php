<?php

return [

    /*
    | The local board UI. Routes exist only in the local environment and only
    | when the app runs from the main checkout (a linked worktree has a .git file).
    | It needs no session, cookie or CSRF token, so `middleware` (extra middleware
    | for the UI routes) is empty by default.
    */
    'ui' => [
        'enabled' => env('KANBAN_UI', true),
        'path' => 'kanban',
        'middleware' => [],
        // Names the UI answers to besides IPs, localhost, *.test, and the hosts of APP_URL and LOCAL_APP_URL (a rebinding page reaches it under its own name).
        'hosts' => [],
        'poll_ms' => 3000,
        'token' => env('KANBAN_UI_TOKEN'),
        'git_author' => env('KANBAN_GIT_AUTHOR'),
    ],

    'agents' => [
        // Passed as --model and --effort to the agents `kanban run` starts (read again for each), and written into the
        // frontmatter of .claude/agents/kanban-{planner,worker,evaluator,merger}.md by kanban:install / doctor --fix. The
        // planner writes each card's plan before it is ready; the worker follows it; the evaluator checks the work; the
        // merger resolves a conflict or judges a red suite when the merge queue merges main into approved work.
        'planner' => ['model' => 'opus', 'effort' => 'high'],
        'worker' => ['model' => 'sonnet', 'effort' => 'high'],
        'evaluator' => ['model' => 'opus', 'effort' => 'medium'],
        'merger' => ['model' => 'opus', 'effort' => 'medium'],
        // container: a card agent's Bash and Monitor commands, git included, run in its stack's `stack.service` container
        // (vendor/bin/kanban-exec), except a plain `vendor/bin/kanban` command. host: they run on this machine.
        'shell' => env('KANBAN_AGENT_SHELL', 'container'),
        // What a headless card agent (`kanban run`) may use besides edits in its card and its routed shell: nobody is there
        // to approve anything else. [] keeps the agents off the web.
        'allowed_tools' => ['WebFetch', 'WebSearch'],
    ],

    'main_branch' => env('KANBAN_MAIN_BRANCH', 'main'),

    'remote' => 'origin',

    // auto: like on while the project has a remote (installing next to one publishes the board; teammates who attach join it), off
    // without. on (also 1, true, yes): always pull before and push after every write. off: board commits are pushed by `publish`.
    'sync' => env('KANBAN_SYNC', 'auto'),

    // With sync on, a clone asks for a sync at most this often (seconds, at least 5; 0 = only after its own writes), whichever
    // tab, hook or command does the asking: that is how what others pushed reaches an idle board.
    'pull_seconds' => (int) env('KANBAN_PULL_SECONDS', 30),

    // Who you are in the board's log ("Ana changed body"): shown beside the role, never used to decide anything. Unset: git's user.name.
    'user' => env('KANBAN_USER'),

    'worktrees' => [
        // Not `kanban/`: git cannot hold `refs/heads/kanban` (the board) and `refs/heads/kanban/…` at once.
        'branch_prefix' => 'card/',
        // Copied from main into each new worktree when main has them; doctor and `start` warn when one is missing or behind its lockfile.
        'copy' => ['vendor', 'node_modules', 'frontend/node_modules'],
        // Host part of worktree URLs; null = the host of main's LOCAL_APP_URL, else localhost.
        'host' => env('KANBAN_HOST'),
    ],

    'stack' => [
        // null disables per-worktree Docker stacks.
        'compose_file' => 'docker-compose.local.yml',
        'project' => '{app}-wt-{name}',
        // The service agents' shells and `stack exec` run in.
        'service' => 'app',
        'pool' => ['base' => 21000, 'block' => 10, 'first' => 1, 'last' => 99],
        // Env key => offset inside the slot's block of ports. Every other host port variable of the compose file takes
        // the next free offset, by name.
        'ports' => ['WEB_PORT' => 0, 'DB_HOST_PORT' => 1, 'REDIS_HOST_PORT' => 2],
        // Written into the worktree .env; {placeholders} are resolved per worktree.
        'env' => [
            'COMPOSE_PROJECT_NAME' => '{project}',
            'SIDECAR_BIND' => '127.0.0.1',
            'LOCAL_APP_URL' => '{scheme}://{host}:{WEB_PORT}',
            'APP_URL' => 'http://localhost:{WEB_PORT}',
            'DB_PORT' => '{DB_HOST_PORT}',
            'REDIS_PORT' => '{REDIS_HOST_PORT}',
            'SESSION_COOKIE' => '{project}-session',
            'XDEBUG_MODE' => 'off',
            'XDEBUG_SERVER_NAME' => '{project}',
            'HMR_CLIENT_PORT' => '',
            'DATABASE_SEED' => 'auto',
        ],
        'health_path' => '/up',
        // `stack wait` gives up with exit 75 after this many seconds (fits the Bash tool's 120 s).
        'wait_timeout' => 110,
        'down' => ['-v', '--remove-orphans', '--rmi', 'local', '-t', '5'],
        // Machine-wide caps and preconditions checked before `up`. Cards in review keep their stacks, so the cap covers
        // doing and review together (6 + 6 by default). The merge stack, one per checkout, is outside them.
        'max_stacks' => env('KANBAN_MAX_STACKS', 12),
        'min_mem_available_gib' => 8,
        'min_disk_free_gib' => 20,
        // Refuse `up`, and warn in doctor, while tmp has less than this share of its space or inodes free.
        'min_free_ratio' => 0.10,
        // Refuse `up` while the 1-minute load is at or above this share of the CPUs.
        'max_load_ratio' => 0.75,
    ],

    // The one migrate command: `finish` runs it in main's stack whenever the main checkout moves (a merge, or following the
    // remote's main), and `kanban context` prints it for the card's agents.
    'migrate' => 'php artisan migrate --force',

    // What the merge queue (`finish`) runs, read from main's config/kanban.php. Each key a project's `finish` leaves out
    // keeps the default below.
    'finish' => [
        // A changed lockfile (by name, at any depth) => the command run in its directory: in the merge stack before the
        // checks, and in the main checkout after the push, where a failure skips the rest.
        'install' => [
            'composer.lock' => 'composer install --no-interaction',
            'package-lock.json' => 'npm ci',
        ],
        // In main's stack after `migrate`, once main moved; a `--class=X` seeder is skipped while database/seeders/X.php does not exist.
        'after' => [
            'php artisan db:seed --class=ReferenceDataSeeder --force',
        ],
        // Required: the whole suite, as it runs where the gates run: inside the app container (no docker or compose), or on
        // this machine with agents.shell host. The merge queue runs the gates, then these, on every merged tree in the
        // merge stack before main moves; no card merges until it is set.
        // An entry is a command or ['run' => '…', 'timeout' => seconds], 1800 by default. A command that runs past its
        // timeout is ended and holds the queue until main moves or its card is blocked.
        'check' => [],
    ],

    // Commands a worker's branch must pass before its report is applied ({main_branch} is replaced). `kanban report`
    // runs them in the worker's worktree, `kanban gates` for the evaluator, and the merge queue on each merged tree in
    // the merge stack, so they must not write files.
    // An entry is a command or ['run' => '…', 'timeout' => seconds, 'when' => 'a path']; `timeout` is the default
    // for the others, and a gate with `when` runs only where that path exists.
    'gates' => [
        'timeout' => 120,
        'report' => [
            'vendor/bin/pint --test --diff={main_branch}',
            'vendor/bin/kanban migrations --base={main_branch}',
            ['run' => 'vendor/bin/kanban data-ids --base={main_branch}', 'when' => 'database/data'],
            ['run' => 'cd frontend && npm run check', 'when' => 'frontend/package.json', 'timeout' => 300],
        ],
    ],

    // Findings about this package that workers and evaluators flag (`--upstream`) are filed as issues on `repo`
    // (`[HOST/]OWNER/REPO`, github.com without a host) by `kanban upstream file`, through gh signed in to that host.
    // Off: they stay in the card logs for the owner (`kanban upstream`).
    'upstream' => [
        'enabled' => env('KANBAN_UPSTREAM', false),
        'repo' => 'petar-spasic/laravel-house',
    ],

    // true: githooks/commit-msg rejects Co-Authored-By trailers and install turns Claude Code's commit and PR
    // attribution off. Applied to git config by attach / doctor --fix.
    'githooks' => [
        'reject_co_authored' => true,
    ],

];
