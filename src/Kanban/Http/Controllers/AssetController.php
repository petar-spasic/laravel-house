<?php

namespace PetarSpasic\LaravelHouse\Kanban\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use PetarSpasic\LaravelHouse\Kanban\Http\Ui;

class AssetController
{
    public function __invoke(Request $request, string $asset): Response
    {
        $type = Ui::ASSETS[$asset] ?? abort(404);
        $current = $request->query('v') === Ui::version($asset) || str_ends_with($asset, '.woff2');

        return response((string) file_get_contents(Ui::asset($asset)), 200, [
            'Content-Type' => $type,
            'Cache-Control' => $current ? 'public, max-age=31536000, immutable' : 'no-cache',
        ]);
    }
}
