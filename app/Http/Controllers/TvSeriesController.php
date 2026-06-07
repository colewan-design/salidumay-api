<?php

namespace App\Http\Controllers;

use App\Models\TvSeries;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class TvSeriesController extends Controller
{
    private const PER_PAGE  = 24;
    private const CACHE_TTL = 3600;
    private const TMDB_BASE = 'https://api.themoviedb.org/3';

    // ── Genres ───────────────────────────────────────────────────────────────

    public function genres(): JsonResponse
    {
        $genres = Cache::remember('tv:genres', 86400, function () {
            $key = config('services.tmdb.key');

            if ($key) {
                $res = Http::timeout(10)
                    ->get(self::TMDB_BASE . '/genre/tv/list', ['api_key' => $key]);
                if ($res->ok()) {
                    return $res->json('genres', []);
                }
            }

            return TvSeries::whereNotNull('genres')
                ->get()
                ->flatMap(fn ($s) => $s->genres ?? [])
                ->unique('id')
                ->sortBy('name')
                ->values()
                ->all();
        });

        return response()->json(['data' => $genres]);
    }

    // ── List endpoints ────────────────────────────────────────────────────────

    public function popular(Request $request): JsonResponse
    {
        return $this->paginatedList(
            TvSeries::popular()->orderByDesc('popularity'),
            $request->integer('page', 1),
            'tv:popular:' . $request->integer('page', 1)
        );
    }

    public function trending(Request $request): JsonResponse
    {
        return $this->paginatedList(
            TvSeries::trending()->orderByDesc('popularity'),
            $request->integer('page', 1),
            'tv:trending:' . $request->integer('page', 1)
        );
    }

    public function topRated(Request $request): JsonResponse
    {
        return $this->paginatedList(
            TvSeries::topRated()->orderByDesc('rating')->orderByDesc('vote_count'),
            $request->integer('page', 1),
            'tv:top_rated:' . $request->integer('page', 1)
        );
    }

    public function airingToday(Request $request): JsonResponse
    {
        return $this->paginatedList(
            TvSeries::airingToday()->orderByDesc('popularity'),
            $request->integer('page', 1),
            'tv:airing_today:' . $request->integer('page', 1)
        );
    }

    // ── Genre filter ──────────────────────────────────────────────────────────

    public function byGenre(Request $request, int $genreId): JsonResponse
    {
        return $this->paginatedList(
            TvSeries::whereJsonContains('genres', ['id' => $genreId])->orderByDesc('popularity'),
            $request->integer('page', 1),
            "tv:genre:{$genreId}:" . $request->integer('page', 1)
        );
    }

    // ── Search ────────────────────────────────────────────────────────────────

    public function search(Request $request): JsonResponse
    {
        $query = trim($request->string('q', ''));
        $page  = $request->integer('page', 1);

        if (strlen($query) < 2) {
            return response()->json(['data' => [], 'pagination' => ['total_pages' => 0, 'current_page' => 1]]);
        }

        $results = TvSeries::where('title', 'like', "%{$query}%")
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

    // ── Detail ────────────────────────────────────────────────────────────────

    public function show(int $id): JsonResponse
    {
        $series = Cache::remember("tv:detail:{$id}", self::CACHE_TTL, function () use ($id) {
            return TvSeries::with(['seasons.episodes'])->find($id);
        });

        if (! $series) {
            return response()->json(['message' => 'Series not found'], 404);
        }

        return response()->json(['data' => $series->toApiArray(withSeasons: true)]);
    }

    // ── Private helper ────────────────────────────────────────────────────────

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
