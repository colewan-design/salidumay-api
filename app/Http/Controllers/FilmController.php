<?php

namespace App\Http\Controllers;

use App\Models\Film;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class FilmController extends Controller
{
    private const PER_PAGE   = 20;
    private const CACHE_TTL  = 3600; // 1 hour
    private const TMDB_BASE  = 'https://api.themoviedb.org/3';

    // ── Genre list ───────────────────────────────────────────────────────────

    /**
     * GET /api/films/genres
     * Returns TMDB genre list, cached for 24 h.
     */
    public function genres(): JsonResponse
    {
        $genres = Cache::remember('films:genres', 86400, function () {
            $key = config('services.tmdb.key');

            if ($key) {
                $res = Http::timeout(10)
                    ->get(self::TMDB_BASE . '/genre/movie/list', ['api_key' => $key]);
                if ($res->ok()) {
                    return $res->json('genres', []);
                }
            }

            // Fallback: derive genres from what's already in the DB
            return Film::whereNotNull('genres')
                ->get()
                ->flatMap(fn($f) => $f->genres ?? [])
                ->unique('id')
                ->sortBy('name')
                ->values()
                ->all();
        });

        return response()->json(['data' => $genres]);
    }

    // ── List endpoints ───────────────────────────────────────────────────────

    /**
     * GET /api/films/popular?page=1
     */
    public function popular(Request $request): JsonResponse
    {
        return $this->paginatedList(
            Film::popular()->orderByDesc('popularity'),
            $request->integer('page', 1),
            'films:popular:' . $request->integer('page', 1)
        );
    }

    /**
     * GET /api/films/trending?page=1
     */
    public function trending(Request $request): JsonResponse
    {
        return $this->paginatedList(
            Film::trending()->orderByDesc('popularity'),
            $request->integer('page', 1),
            'films:trending:' . $request->integer('page', 1)
        );
    }

    /**
     * GET /api/films/top-rated?page=1
     */
    public function topRated(Request $request): JsonResponse
    {
        return $this->paginatedList(
            Film::topRated()->orderByDesc('rating')->orderByDesc('vote_count'),
            $request->integer('page', 1),
            'films:top_rated:' . $request->integer('page', 1)
        );
    }

    /**
     * GET /api/films/now-playing?page=1
     */
    public function nowPlaying(Request $request): JsonResponse
    {
        return $this->paginatedList(
            Film::nowPlaying()->orderByDesc('popularity'),
            $request->integer('page', 1),
            'films:now_playing:' . $request->integer('page', 1)
        );
    }

    // ── Genre filter ─────────────────────────────────────────────────────────

    /**
     * GET /api/films/genre/{genreId}?page=1
     */
    public function byGenre(Request $request, int $genreId): JsonResponse
    {
        $page  = $request->integer('page', 1);
        $cache = "films:genre:{$genreId}:{$page}";

        return $this->paginatedList(
            Film::whereJsonContains('genres', ['id' => $genreId])->orderByDesc('popularity'),
            $page,
            $cache
        );
    }

    // ── Search ───────────────────────────────────────────────────────────────

    /**
     * GET /api/films/search?q=inception&page=1
     */
    public function search(Request $request): JsonResponse
    {
        $query = trim($request->string('q', ''));
        $page  = $request->integer('page', 1);

        if (strlen($query) < 2) {
            return response()->json(['data' => [], 'pagination' => ['total_pages' => 0, 'current_page' => 1]]);
        }

        $results = Film::where('title', 'like', "%{$query}%")
            ->orWhere('original_title', 'like', "%{$query}%")
            ->orderByDesc('popularity')
            ->paginate(self::PER_PAGE, ['*'], 'page', $page);

        return response()->json([
            'data'       => collect($results->items())->map->toApiArray()->values(),
            'pagination' => [
                'total_pages'  => $results->lastPage(),
                'current_page' => $results->currentPage(),
                'total'        => $results->total(),
            ],
        ]);
    }

    // ── Detail ───────────────────────────────────────────────────────────────

    /**
     * GET /api/films/{id}
     * Returns film with cast and similar films.
     */
    public function show(int $id): JsonResponse
    {
        $film = Cache::remember("films:detail:{$id}", self::CACHE_TTL, function () use ($id) {
            return Film::find($id);
        });

        if (! $film) {
            return response()->json(['message' => 'Film not found'], 404);
        }

        return response()->json(['data' => $film->toApiArray(withSimilar: true)]);
    }

    // ── Private helper ───────────────────────────────────────────────────────

    private function paginatedList($query, int $page, string $cacheKey): JsonResponse
    {
        $results = Cache::remember($cacheKey, self::CACHE_TTL, function () use ($query, $page) {
            return $query->paginate(self::PER_PAGE, ['*'], 'page', $page);
        });

        return response()->json([
            'data'       => collect($results->items())->map->toApiArray()->values(),
            'pagination' => [
                'total_pages'  => $results->lastPage(),
                'current_page' => $results->currentPage(),
                'total'        => $results->total(),
                'has_next'     => $results->hasMorePages(),
            ],
        ]);
    }
}
