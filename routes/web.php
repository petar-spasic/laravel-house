<?php

use Illuminate\Support\Facades\Route;
use PetarSpasic\Kanban\Http\Controllers\AssetController;
use PetarSpasic\Kanban\Http\Controllers\BoardController;
use PetarSpasic\Kanban\Http\Controllers\CardController;

$slug = '[a-z0-9]+(?:-[a-z0-9]+)*';

Route::get('/assets/{asset}', AssetController::class)->name('asset');

Route::get('/cards/{card}', [CardController::class, 'show'])->name('card');
Route::post('/cards/{card}/stage', [CardController::class, 'stage'])->name('card.stage');
Route::post('/cards/{card}/priority', [CardController::class, 'priority'])->name('card.priority');
Route::post('/cards/{card}/blocked', [CardController::class, 'blocked'])->name('card.blocked');
Route::post('/cards/{card}/notes', [CardController::class, 'notes'])->name('card.notes');

Route::get('/', [BoardController::class, 'index'])->name('index');
Route::get('/{epic}/{board}', [BoardController::class, 'show'])->name('board')->where(['epic' => $slug, 'board' => $slug]);
Route::get('/{epic}/{board}/columns', [BoardController::class, 'columns'])->name('columns')->where(['epic' => $slug, 'board' => $slug]);
