<?php

namespace PetarSpasic\Kanban\Support;

use Illuminate\Support\Str;

final class Markdown
{
    public static function render(string $markdown): string
    {
        return (string) Str::markdown($markdown, ['html_input' => 'escape', 'allow_unsafe_links' => false, 'max_nesting_level' => 100]);
    }
}
