<?php

use App\Http\Controllers\AnimeController;
use Illuminate\Support\Facades\Route;

Route::prefix('anime')->group(function () {
    Route::get('hero',        [AnimeController::class, 'hero']);
    Route::get('trending',    [AnimeController::class, 'trending']);
    Route::get('seasonal',    [AnimeController::class, 'seasonal']);
    Route::get('genres',      [AnimeController::class, 'genres']);
    Route::get('top',         [AnimeController::class, 'top']);
    Route::get('list',        [AnimeController::class, 'list']);
    Route::get('search',      [AnimeController::class, 'search']);

    Route::get('{id}',                [AnimeController::class, 'detail']);
    Route::get('{id}/episodes',       [AnimeController::class, 'episodes']);
    Route::get('{id}/streaming',      [AnimeController::class, 'streaming']);
    Route::get('{id}/related',        [AnimeController::class, 'related']);
})->where(['id' => '[0-9]+']);
