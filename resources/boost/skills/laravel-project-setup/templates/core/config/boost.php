<?php

return [
    // Boost 2.10 writes Claude Code's guidelines to AGENTS.md by default; ours end the root CLAUDE.md.
    'agents' => [
        'claude_code' => [
            'guidelines_path' => 'CLAUDE.md',
        ],
    ],
];
