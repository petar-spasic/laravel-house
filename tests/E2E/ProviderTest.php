<?php

it('merges the package config', function () {
    expect(config('kanban.stack.pool.base'))->toBe(21000)
        ->and(config('kanban.ui.path'))->toBe('kanban');
});
