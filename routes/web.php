<?php

use Illuminate\Support\Facades\Route;
use PetarSpasic\LaravelHouse\Kanban\Http\Controllers\AssetController;
use PetarSpasic\LaravelHouse\Kanban\Http\Controllers\BoardsController;
use PetarSpasic\LaravelHouse\Kanban\Http\Controllers\CardsController;
use PetarSpasic\LaravelHouse\Kanban\Http\Controllers\ShellController;

$slug = '[a-z0-9]+(?:-[a-z0-9]+)*';

Route::get('/assets/{asset}', AssetController::class)->name('asset');

// `_api` is not a slug, so no board can collide with it.
Route::prefix('_api')->name('api.')->group(function () use ($slug) {
    Route::get('/boards', [BoardsController::class, 'index'])->name('boards');
    Route::get('/cards', [CardsController::class, 'index'])->name('cards');
    Route::get('/cards/{card}', [CardsController::class, 'show'])->name('card');
    Route::patch('/cards/{card}', [CardsController::class, 'patch'])->name('card.patch');
    Route::post('/cards/{card}/stage', [CardsController::class, 'stage'])->name('card.stage');
    Route::post('/cards/{card}/notes', [CardsController::class, 'notes'])->name('card.notes');
    Route::post('/{board}/cards', [CardsController::class, 'store'])->name('card.store')->where(['board' => $slug]);
    Route::get('/{board}', [BoardsController::class, 'show'])->name('board')->where(['board' => $slug]);
    Route::any('/{path?}', fn () => abort(404, 'Not found'))->where('path', '.*')->fallback()->name('missing');
});

Route::get('/', ShellController::class)->name('index');
Route::get('/cards/{card}', ShellController::class)->name('card');
Route::get('/{board}', ShellController::class)->name('board')->where(['board' => $slug]);
// a link from before boards left their epic directories; the page sends it on to /{board}
Route::get('/{epic}/{board}', ShellController::class)->name('board.moved')->where(['epic' => $slug, 'board' => $slug]);
