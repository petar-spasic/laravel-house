<?php

return [

    /*
    | The local board UI. Routes exist only in the local environment and only
    | when the app runs from the main checkout (a linked worktree has a .git file).
    */
    'ui' => [
        'enabled' => env('KANBAN_UI', true),
        'path' => 'kanban',
        'middleware' => ['web'],
        'poll_ms' => 3000,
        'token' => env('KANBAN_UI_TOKEN'),
        'git_author' => env('KANBAN_GIT_AUTHOR'),
    ],

    // Written into the frontmatter of .claude/agents/kanban-{worker,evaluator}.md by kanban:install / doctor --fix.
    'agents' => [
        'worker' => ['model' => 'sonnet', 'effort' => 'high'],
        'evaluator' => ['model' => 'opus', 'effort' => 'medium'],
    ],

    'main_branch' => env('KANBAN_MAIN_BRANCH', 'main'),

    'remote' => 'origin',

    // off: board commits are pushed by `publish`; on (also 1, true, yes): pull before and push after every write (teams).
    'sync' => env('KANBAN_SYNC', 'off'),

    'worktrees' => [
        // Not `kanban/`: git cannot hold `refs/heads/kanban` (the board) and `refs/heads/kanban/…` at once.
        'branch_prefix' => 'card/',
        'copy' => ['vendor', 'node_modules'],
        // Host part of worktree URLs; null = the host of main's LOCAL_APP_URL, else localhost.
        'host' => env('KANBAN_HOST'),
    ],

    'stack' => [
        // null disables per-worktree Docker stacks.
        'compose_file' => 'docker-compose.local.yml',
        'project' => '{app}-wt-{name}',
        'pool' => ['base' => 21000, 'block' => 10, 'first' => 1, 'last' => 99],
        // Env key => offset inside the slot's block of ports.
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
        // Machine-wide caps and preconditions checked before `up`.
        'max_stacks' => env('KANBAN_MAX_STACKS', 6),
        'min_mem_available_gib' => 8,
        'min_disk_free_gib' => 20,
        // Refuse `up` while the 1-minute load is at or above this share of the CPUs.
        'max_load_ratio' => 0.75,
    ],

    // Run in the main checkout after `finish` merges, when the project has a stack; a `--class=X` seeder
    // command is skipped while database/seeders/X.php does not exist.
    'finish' => [
        'after' => [
            'php artisan migrate --force',
            'php artisan db:seed --class=ReferenceDataSeeder --force',
        ],
    ],

    // Commands a worker's branch must pass before its report is applied ({main_branch} is replaced);
    // `kanban context` prints them for the worker and the evaluator.
    'gates' => [
        'report' => [
            'vendor/bin/pint --test --diff={main_branch}',
        ],
    ],

    // true: githooks/commit-msg rejects Co-Authored-By trailers and install turns Claude Code's commit and PR
    // attribution off. Applied to git config by attach / doctor --fix.
    'githooks' => [
        'reject_co_authored' => true,
    ],

];
