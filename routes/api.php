<?php

use App\Http\Controllers\AnimeController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CommentController;
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
})->where(['id' => '[0-9]+']);

// Comment routes (GET public, POST/DELETE requires auth)
Route::get('comments/{animeId}', [CommentController::class, 'index']);
Route::middleware('auth:sanctum')->group(function () {
    Route::post('comments/{animeId}',          [CommentController::class, 'store']);
    Route::delete('comments/{animeId}/{comment}', [CommentController::class, 'destroy']);
});

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
