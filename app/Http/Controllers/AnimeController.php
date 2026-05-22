<?php

namespace App\Http\Controllers;

use App\Models\Anime;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class AnimeController extends Controller
{
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

    public function detail(int $id): JsonResponse
    {
        $anime = Anime::find($id);
        if ($anime) {
            return response()->json(['data' => $anime->toApiArray()]);
        }

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
                    'title'      => $item['title'] ?? '',
                    'subtitle'   => $item['title_japanese'] ?? $item['title_english'] ?? null,
                    'image'      => $item['images']['jpg']['large_image_url'] ?? $item['images']['jpg']['image_url'] ?? null,
                    'genre'      => $item['genres'][0]['name'] ?? 'Anime',
                    'badge'      => $item['genres'][0]['name'] ?? 'Anime',
                    'rating'     => is_numeric($item['score'] ?? null) ? (float) $item['score'] : 0,
                    'episodes'   => $item['episodes'] ?? null,
                    'status'     => $status === 'Currently Airing' ? 'Airing' : 'Done',
                    'year'       => $item['year'] ?? $item['aired']['prop']['from']['year'] ?? null,
                    'studio'     => $item['studios'][0]['name'] ?? null,
                    'synopsis'   => $item['synopsis'] ?? null,
                    'members'    => $item['members'] ?? 0,
                    'is_new'     => $status === 'Currently Airing',
                    'is_airing'  => $status === 'Currently Airing',
                    'trailer_url' => isset($item['trailer']['embed_url'])
                        ? str_replace('autoplay=1', 'autoplay=0', $item['trailer']['embed_url']) . '&enablejsapi=0'
                        : null,
                    'scraped_at' => now(),
                ]);
            }
            return $this->normalizeAnime($item);
        });

        return response()->json(['data' => $data]);
    }

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
                $title = $anime?->title ?? '';
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

    private function normalizeAnime(?array $item): ?array
    {
        if (!$item) return null;
        $status = $item['status'] ?? '';
        return [
            'id'         => $item['mal_id'],
            'title'      => $item['title'] ?? '',
            'subtitle'   => $item['title_japanese'] ?? $item['title_english'] ?? '',
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
