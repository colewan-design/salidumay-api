<?php

use App\Http\Controllers\AnimeController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CommentController;
use App\Http\Controllers\FilmController;
use App\Http\Controllers\TvSeriesController;
use App\Http\Controllers\LibraryController;
use App\Http\Controllers\HistoryController;
use Illuminate\Support\Facades\Route;

Route::prefix('anime')->group(function () {
    Route::get('hero',        [AnimeController::class, 'hero']);
    Route::get('trending',    [AnimeController::class, 'trending']);
    Route::get('seasonal',    [AnimeController::class, 'seasonal']);
    Route::get('genres',      [AnimeController::class, 'genres']);
    Route::get('top',         [AnimeController::class, 'top']);
    Route::get('list',        [AnimeController::class, 'list']);
    Route::get('movies',      [AnimeController::class, 'movies']);
    Route::get('rankings',    [AnimeController::class, 'rankings']);
    Route::get('genre/{genre}', [AnimeController::class, 'byGenre']);
    Route::get('search',      [AnimeController::class, 'search']);

    Route::get('{id}',                [AnimeController::class, 'detail']);
    Route::get('{id}/episodes',       [AnimeController::class, 'episodes']);
    Route::get('{id}/streaming',      [AnimeController::class, 'streaming']);
    Route::get('{id}/related',        [AnimeController::class, 'related']);
    Route::get('{id}/seasons',        [AnimeController::class, 'seasons']);

    // Engagement — views (public, 30 req/min) and reactions (auth only)
    Route::post('{id}/view',  [AnimeController::class, 'view'])->middleware('throttle:30,1');
    Route::post('{id}/react', [AnimeController::class, 'react'])->middleware('auth:sanctum');
})->where(['id' => '[0-9]+']);

// TV Series (TMDB — all public)
Route::prefix('tv')->group(function () {
    Route::get('genres',          [TvSeriesController::class, 'genres']);
    Route::get('popular',         [TvSeriesController::class, 'popular']);
    Route::get('trending',        [TvSeriesController::class, 'trending']);
    Route::get('top-rated',       [TvSeriesController::class, 'topRated']);
    Route::get('airing-today',    [TvSeriesController::class, 'airingToday']);
    Route::get('search',          [TvSeriesController::class, 'search']);
    Route::get('genre/{genreId}', [TvSeriesController::class, 'byGenre']);
    Route::get('{id}',            [TvSeriesController::class, 'show']);
})->where(['id' => '[0-9]+', 'genreId' => '[0-9]+']);

// Films (TMDB — all public)
Route::prefix('films')->group(function () {
    Route::get('genres',            [FilmController::class, 'genres']);
    Route::get('popular',           [FilmController::class, 'popular']);
    Route::get('trending',          [FilmController::class, 'trending']);
    Route::get('top-rated',         [FilmController::class, 'topRated']);
    Route::get('now-playing',       [FilmController::class, 'nowPlaying']);
    Route::get('upcoming',          [FilmController::class, 'upcoming']);
    Route::get('search',            [FilmController::class, 'search']);
    Route::get('genre/{genreId}',   [FilmController::class, 'byGenre']);
    Route::get('{id}',              [FilmController::class, 'show']);

    // Engagement — views (public, 30 req/min) and reactions (auth only)
    Route::post('{id}/view',  [FilmController::class, 'view'])->middleware('throttle:30,1');
    Route::post('{id}/react', [FilmController::class, 'react'])->middleware('auth:sanctum');
})->where(['id' => '[0-9]+', 'genreId' => '[0-9]+']);

// Comment routes (GET public, POST/DELETE requires auth)
Route::get('comments/{animeId}', [CommentController::class, 'index']);
Route::middleware('auth:sanctum')->group(function () {
    Route::post('comments/{animeId}',          [CommentController::class, 'store']);
    Route::delete('comments/{animeId}/{comment}', [CommentController::class, 'destroy']);
});

// User library & history (auth required)
Route::middleware('auth:sanctum')->prefix('user')->group(function () {
    Route::get('library',              [LibraryController::class, 'index']);
    Route::post('library',             [LibraryController::class, 'store']);
    Route::delete('library/{animeId}', [LibraryController::class, 'destroy']);

    Route::get('history',              [HistoryController::class, 'index']);
    Route::post('history',             [HistoryController::class, 'store']);
    Route::delete('history',           [HistoryController::class, 'clear']);
    Route::delete('history/{animeId}', [HistoryController::class, 'destroy']);
});

// Cron trigger (called by cron-job.org every minute)
Route::get('cron/run', function () {
    try {
        Illuminate\Support\Facades\Artisan::call('schedule:run');
        $output = Illuminate\Support\Facades\Artisan::output();
        return response()->json(['ok' => true, 'output' => $output]);
    } catch (\Throwable $e) {
        return response()->json([
            'ok'    => false,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ], 500);
    }
})->middleware('throttle:1,1');

// Auth routes
Route::prefix('auth')->group(function () {
    Route::post('register', [AuthController::class, 'register']);
    Route::post('login',    [AuthController::class, 'login']);
    Route::get('google',           [AuthController::class, 'googleRedirect']);
    Route::get('google/callback',  [AuthController::class, 'googleCallback']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me',      [AuthController::class, 'me']);
    });
});
