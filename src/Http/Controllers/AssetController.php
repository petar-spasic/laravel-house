<?php

namespace PetarSpasic\Kanban\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use PetarSpasic\Kanban\Http\Ui;

class AssetController
{
    public function __invoke(Request $request, string $asset): Response
    {
        $type = Ui::ASSETS[$asset] ?? abort(404);
        $current = $request->query('v') === Ui::version($asset);

        return response((string) file_get_contents(Ui::asset($asset)), 200, [
            'Content-Type' => $type,
            'Cache-Control' => $current ? 'public, max-age=31536000, immutable' : 'no-cache',
        ]);
    }
}
