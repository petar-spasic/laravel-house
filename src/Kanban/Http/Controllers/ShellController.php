<?php

namespace PetarSpasic\LaravelHouse\Kanban\Http\Controllers;

use Illuminate\Contracts\View\View;
use PetarSpasic\LaravelHouse\Kanban\Store\Card;

/** The one page of the UI: the boards, a board and a card are all drawn by the script. */
class ShellController
{
    public function __invoke(): View
    {
        return view('kanban::app', [
            'base' => rtrim((string) parse_url(route('kanban.index'), PHP_URL_PATH), '/'),
            'pollMs' => (int) config('kanban.ui.poll_ms', 3000),
            'maxCriteria' => Card::MAX_CRITERIA,
            'maxCriterion' => Card::MAX_CRITERION,
        ]);
    }
}
