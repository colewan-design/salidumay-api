<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\HandlesEngagement;
use App\Models\Anime;
use App\Models\AnimeWatchOrder;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class AnimeController extends Controller
{
    use HandlesEngagement;

    public function hero(): JsonResponse
    {
        $anime = Anime::where('in_hero', true)->first();
        return response()->json(['data' => $anime?->toApiArray()]);
    }

    public function trending(): JsonResponse
    {
        $list = Anime::where('in_trending', true)
            ->orderByDesc('members')
            ->get()
            ->map->toApiArray()
            ->values();
        return response()->json(['data' => $list]);
    }

    public function seasonal(): JsonResponse
    {
        $list = Anime::where('in_seasonal', true)
            ->orderByDesc('members')
            ->get()
            ->map->toApiArray()
            ->values();
        return response()->json(['data' => $list]);
    }

    public function genres(): JsonResponse
    {
        $data = Cache::remember('anime:genres', 86400, function () {
            $response = Http::timeout(15)
                ->accept('application/json')
                ->get('https://api.jikan.moe/v4/genres/anime');
            if ($response->failed()) return [];
            $genres = array_slice($response->json()['data'] ?? [], 0, 24);
            return array_map(fn($g) => ['name' => $g['name'], 'id' => $g['mal_id']], $genres);
        });
        return response()->json(['data' => $data]);
    }

    public function top(): JsonResponse
    {
        $list = Anime::where('in_top', true)
            ->orderByDesc('rating')
            ->limit(10)
            ->get()
            ->map->toApiArray()
            ->values();
        return response()->json(['data' => $list]);
    }

    public function movies(Request $request): JsonResponse
    {
        $page    = max(1, (int) $request->query('page', 1));
        $perPage = 24;

        $query = Anime::where('in_movies', true)->orderByDesc('members');
        $total = $query->count();
        $items = $query->offset(($page - 1) * $perPage)->limit($perPage)->get()->map->toApiArray()->values();

        return response()->json([
            'data'       => $items,
            'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'has_next' => ($page * $perPage) < $total],
        ]);
    }

    public function rankings(Request $request): JsonResponse
    {
        $page    = max(1, (int) $request->query('page', 1));
        $perPage = 25;

        $query = Anime::orderByDesc('members');

        $total = $query->count();
        $items = $query->offset(($page - 1) * $perPage)->limit($perPage)->get()->map->toApiArray()->values();

        return response()->json([
            'data'       => $items,
            'pagination' => [
                'page'      => $page,
                'per_page'  => $perPage,
                'total'     => $total,
                'has_next'  => ($page * $perPage) < $total,
            ],
        ]);
    }

    public function byGenre(Request $request, string $genre): JsonResponse
    {
        $page    = max(1, (int) $request->query('page', 1));
        $perPage = 24;

        $query = Anime::where('genre', $genre)->orderByDesc('members');

        $total = $query->count();
        $items = $query->offset(($page - 1) * $perPage)->limit($perPage)->get()->map->toApiArray()->values();

        return response()->json([
            'data'       => $items,
            'pagination' => [
                'page'      => $page,
                'per_page'  => $perPage,
                'total'     => $total,
                'has_next'  => ($page * $perPage) < $total,
            ],
        ]);
    }

    public function list(): JsonResponse
    {
        $list = Anime::orderByDesc('members')
            ->limit(25)
            ->get()
            ->map->toApiArray()
            ->values();
        return response()->json(['data' => $list]);
    }

    public function search(Request $request): JsonResponse
    {
        $query = trim($request->query('q', ''));
        if ($query === '') {
            return response()->json(['data' => []]);
        }

        // Search DB first
        $local = Anime::where('title', 'like', "%{$query}%")
            ->orderByDesc('members')
            ->limit(25)
            ->get()
            ->map->toApiArray()
            ->values();

        if ($local->isNotEmpty()) {
            return response()->json(['data' => $local]);
        }

        // Fall back to Jikan for titles not yet scraped
        $key = 'anime:search:' . md5(strtolower($query));
        $data = Cache::remember($key, 1800, function () use ($query) {
            $response = Http::timeout(15)
                ->accept('application/json')
                ->get('https://api.jikan.moe/v4/anime', ['q' => $query, 'limit' => 25, 'sfw' => 'true']);
            if ($response->failed()) return [];
            return array_map([$this, 'normalizeAnime'], $response->json()['data'] ?? []);
        });

        return response()->json(['data' => $data]);
    }

    public function detail(Request $request, int $id): JsonResponse
    {
        $data  = null;
        $anime = Anime::find($id);

        if ($anime) {
            if (empty($anime->english_title)) {
                $anime = $this->backfillEnglishTitle($anime, $id);
            }
            $data = $anime->toApiArray();
        } else {
            // Not in DB yet — fetch live and store it
            $key  = "anime:detail:{$id}";
            $data = Cache::remember($key, 21600, function () use ($id) {
                $response = Http::timeout(15)
                    ->accept('application/json')
                    ->get("https://api.jikan.moe/v4/anime/{$id}");
                if ($response->failed()) return null;
                $item = $response->json()['data'] ?? null;
                if ($item) {
                    $status = $item['status'] ?? '';
                    Anime::updateOrCreate(['mal_id' => $id], [
                        'title'         => $item['title'] ?? '',
                        'subtitle'      => $item['title_japanese'] ?? $item['title_english'] ?? null,
                        'english_title' => $item['title_english'] ?? null,
                        'image'         => $item['images']['jpg']['large_image_url'] ?? $item['images']['jpg']['image_url'] ?? null,
                        'genre'         => $item['genres'][0]['name'] ?? 'Anime',
                        'badge'         => $item['genres'][0]['name'] ?? 'Anime',
                        'rating'        => is_numeric($item['score'] ?? null) ? (float) $item['score'] : 0,
                        'episodes'      => $item['episodes'] ?? null,
                        'status'        => $status === 'Currently Airing' ? 'Airing' : 'Done',
                        'year'          => $item['year'] ?? $item['aired']['prop']['from']['year'] ?? null,
                        'studio'        => $item['studios'][0]['name'] ?? null,
                        'synopsis'      => $item['synopsis'] ?? null,
                        'members'       => $item['members'] ?? 0,
                        'is_new'        => $status === 'Currently Airing',
                        'is_airing'     => $status === 'Currently Airing',
                        'trailer_url'   => isset($item['trailer']['embed_url'])
                            ? str_replace('autoplay=1', 'autoplay=0', $item['trailer']['embed_url']) . '&enablejsapi=0'
                            : null,
                        'scraped_at'    => now(),
                    ]);
                }
                return $this->normalizeAnime($item);
            });
        }

        // Append live engagement counts (views are unique-IP, likes require auth)
        if (is_array($data)) {
            $data += $this->engagementData('anime', $id);
        }

        return response()->json(['data' => $data]);
    }

    // ── Engagement endpoints ─────────────────────────────────────────────────

    /** POST /api/anime/{id}/view  (public, throttled) */
    public function view(Request $request, int $id): JsonResponse
    {
        return $this->recordView($request, 'anime', $id);
    }

    /** POST /api/anime/{id}/react  (auth required) */
    public function react(Request $request, int $id): JsonResponse
    {
        return $this->toggleReaction($request, 'anime', $id);
    }

    // ── Episode / streaming helpers ──────────────────────────────────────────

    public function episodes(int $id): JsonResponse
    {
        $key = "anime:episodes:{$id}";
        $data = Cache::remember($key, 86400, function () use ($id) {
            $episodes = [];
            $page = 1;

            do {
                $response = Http::timeout(15)->accept('application/json')
                    ->get("https://api.jikan.moe/v4/anime/{$id}/episodes", ['page' => $page]);
                $json  = $response->json() ?? [];
                $batch = $json['data'] ?? [];

                foreach ($batch as $ep) {
                    $episodes[] = [
                        'number'    => $ep['mal_id'],
                        'title'     => $ep['title'] ?: "Episode {$ep['mal_id']}",
                        'duration'  => null,
                        'thumbnail' => null,
                        'videoUrl'  => null,
                    ];
                }

                $hasNext = ($json['pagination']['has_next_page'] ?? false) && count($batch) > 0;
                $page++;
                if ($hasNext) usleep(400000);
            } while ($hasNext);

            if (empty($episodes)) {
                $anime = Anime::find($id);
                $total = is_numeric($anime?->episodes) ? (int) $anime->episodes : 1;
                for ($n = 1; $n <= max(1, $total); $n++) {
                    $episodes[] = ['number' => $n, 'title' => "Episode {$n}", 'duration' => null, 'thumbnail' => null, 'videoUrl' => null];
                }
            }

            return ['data' => $episodes];
        });

        return response()->json($data);
    }

    public function streaming(int $id): JsonResponse
    {
        $key  = "anime:streaming:{$id}";
        $data = Cache::remember($key, 21600, function () use ($id) {
            $response = Http::timeout(15)->accept('application/json')
                ->get("https://api.jikan.moe/v4/anime/{$id}/streaming");
            return ['data' => $response->json()['data'] ?? []];
        });
        return response()->json($data);
    }

    public function related(int $id): JsonResponse
    {
        $key  = "anime:related:{$id}";
        $data = Cache::remember($key, 21600, function () use ($id) {
            $response = Http::timeout(15)->accept('application/json')
                ->get("https://api.jikan.moe/v4/anime/{$id}/recommendations");
            $items = array_slice($response->json()['data'] ?? [], 0, 10);
            return ['data' => array_map(fn($rec) => $this->normalizeAnime($rec['entry'] ?? null), $items)];
        });
        return response()->json($data);
    }

    public function seasons(int $id, Request $request): JsonResponse
    {
        $key = "anime:seasons:{$id}";
        if ($request->boolean('refresh')) Cache::forget($key);

        $data = Cache::remember($key, 86400, function () use ($id) {
            // Serve from DB if already scraped
            $rows = AnimeWatchOrder::where('anime_id', $id)
                ->orderByRaw('FIELD(relation_type, ' . implode(',', array_map(
                    fn($t) => "'{$t}'",
                    array_keys(AnimeWatchOrder::TYPE_ORDER)
                )) . ')')
                ->get();

            if ($rows->isNotEmpty()) {
                return ['data' => $rows->map->toApiArray()->values()];
            }

            // Nothing in DB yet — fall back to Jikan and queue a background scrape
            $response = Http::timeout(15)->accept('application/json')
                ->get("https://api.jikan.moe/v4/anime/{$id}/relations");

            $entries = [];
            foreach ($response->json()['data'] ?? [] as $rel) {
                foreach ($rel['entry'] ?? [] as $entry) {
                    if (($entry['type'] ?? '') !== 'anime') continue;
                    $entries[] = ['mal_id' => $entry['mal_id'], 'name' => $entry['name'], 'relation' => $rel['relation']];
                }
            }

            if (empty($entries)) return ['data' => []];

            $malIds  = array_column($entries, 'mal_id');
            $dbAnime = Anime::whereIn('mal_id', $malIds)->get()->keyBy('mal_id');

            $seasons = [];
            foreach ($entries as $entry) {
                $local = $dbAnime->get($entry['mal_id']);
                if ($local) {
                    $seasons[] = array_merge($local->toApiArray(), ['relation' => $entry['relation']]);
                } else {
                    $res  = Http::timeout(15)->accept('application/json')
                        ->get("https://api.jikan.moe/v4/anime/{$entry['mal_id']}");
                    $item = $res->json()['data'] ?? null;
                    $normalized = $item ? $this->normalizeAnime($item) : ['id' => $entry['mal_id'], 'title' => $entry['name']];
                    $seasons[] = array_merge($normalized, ['relation' => $entry['relation']]);
                    usleep(700000);
                }
            }

            return ['data' => $seasons];
        });

        return response()->json($data);
    }

    // ── Private helpers ──────────────────────────────────────────────────────

    private function backfillEnglishTitle(Anime $anime, int $id): Anime
    {
        $response = Http::timeout(8)->accept('application/json')
            ->get("https://api.jikan.moe/v4/anime/{$id}");
        if ($response->successful()) {
            $englishTitle = $response->json()['data']['title_english'] ?? null;
            if ($englishTitle) {
                $anime->english_title = $englishTitle;
                $anime->save();
            }
        }
        return $anime;
    }

    private function normalizeAnime(?array $item): ?array
    {
        if (!$item) return null;
        $status = $item['status'] ?? '';
        return [
            'id'           => $item['mal_id'],
            'title'        => $item['title'] ?? '',
            'subtitle'     => $item['title_japanese'] ?? $item['title_english'] ?? '',
            'englishTitle' => $item['title_english'] ?? null,
            'image'      => $item['images']['jpg']['large_image_url'] ?? $item['images']['jpg']['image_url'] ?? '',
            'genre'      => $item['genres'][0]['name'] ?? $item['demographics'][0]['name'] ?? 'Anime',
            'badge'      => $item['genres'][0]['name'] ?? 'Anime',
            'rating'     => is_numeric($item['score'] ?? null) ? (float) $item['score'] : 0,
            'episodes'   => $item['episodes'] ?? '?',
            'status'     => $status === 'Currently Airing' ? 'Airing' : 'Done',
            'year'       => $item['year'] ?? $item['aired']['prop']['from']['year'] ?? null,
            'studio'     => $item['studios'][0]['name'] ?? '',
            'synopsis'   => $item['synopsis'] ?? '',
            'members'    => $item['members'] ?? 0,
            'new'        => $status === 'Currently Airing',
            'trailerUrl' => isset($item['trailer']['embed_url'])
                ? str_replace('autoplay=1', 'autoplay=0', $item['trailer']['embed_url']) . '&enablejsapi=0'
                : null,
        ];
    }
}
