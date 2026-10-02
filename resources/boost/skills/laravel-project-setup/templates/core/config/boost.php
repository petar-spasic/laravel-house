<?php

return [
    // Boost 2.10 writes Claude Code's guidelines to AGENTS.md by default; ours end the root CLAUDE.md.
    'agents' => [
        'claude_code' => [
            'guidelines_path' => 'CLAUDE.md',
        ],
    ],

    // The CLAUDE.md files are the rules: no record-rule tool, no .ai/rules.
    'rules' => [
        'enabled' => false,
    ],
<!-- if:spa -->

    // The SvelteKit pages are not Laravel's: Boost's logger has no page to inject into.
    'browser_logs_watcher' => false,
<!-- endif -->
];
