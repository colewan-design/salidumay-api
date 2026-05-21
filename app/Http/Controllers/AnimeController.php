<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\JsonResponse;

class AnimeController extends Controller
{
    private const JIKAN = 'https://api.jikan.moe/v4';

    private function jikan(string $path, array $params = []): array
    {
        $response = Http::timeout(15)
            ->accept('application/json')
            ->get(self::JIKAN . $path, $params);

        if ($response->failed()) {
            abort($response->status(), 'Jikan API error');
        }

        return $response->json();
    }

    private function cached(string $key, int $ttlSeconds, callable $callback): JsonResponse
    {
        $data = Cache::remember($key, $ttlSeconds, $callback);
        return response()->json($data);
    }

    public function hero(): JsonResponse
    {
        return $this->cached('anime:hero', 3600, function () {
            $json = $this->jikan('/top/anime', ['filter' => 'airing', 'limit' => 1]);
            return ['data' => $this->normalizeAnime($json['data'][0] ?? null)];
        });
    }

    public function trending(): JsonResponse
    {
        return $this->cached('anime:trending', 3600, function () {
            $json = $this->jikan('/top/anime', ['filter' => 'airing', 'limit' => 12]);
            return ['data' => array_map([$this, 'normalizeAnime'], $json['data'] ?? [])];
        });
    }

    public function seasonal(): JsonResponse
    {
        return $this->cached('anime:seasonal', 3600, function () {
            $json = $this->jikan('/seasons/now', ['limit' => 16]);
            return ['data' => array_map([$this, 'normalizeAnime'], $json['data'] ?? [])];
        });
    }

    public function genres(): JsonResponse
    {
        return $this->cached('anime:genres', 86400, function () {
            $json = $this->jikan('/genres/anime');
            $genres = array_slice($json['data'] ?? [], 0, 24);
            return ['data' => array_map(fn($g) => ['name' => $g['name'], 'id' => $g['mal_id']], $genres)];
        });
    }

    public function top(): JsonResponse
    {
        return $this->cached('anime:top', 3600, function () {
            $json = $this->jikan('/top/anime', ['limit' => 10]);
            return ['data' => array_map([$this, 'normalizeAnime'], $json['data'] ?? [])];
        });
    }

    public function list(): JsonResponse
    {
        return $this->cached('anime:list', 3600, function () {
            $json = $this->jikan('/top/anime', ['limit' => 25]);
            return ['data' => array_map([$this, 'normalizeAnime'], $json['data'] ?? [])];
        });
    }

    public function search(Request $request): JsonResponse
    {
        $query = trim($request->query('q', ''));
        if ($query === '') {
            return response()->json(['data' => []]);
        }

        $key = 'anime:search:' . md5(strtolower($query));
        return $this->cached($key, 1800, function () use ($query) {
            $json = $this->jikan('/anime', ['q' => $query, 'limit' => 25, 'sfw' => 'true']);
            return ['data' => array_map([$this, 'normalizeAnime'], $json['data'] ?? [])];
        });
    }

    public function detail(int $id): JsonResponse
    {
        return $this->cached("anime:detail:{$id}", 21600, function () use ($id) {
            $json = $this->jikan("/anime/{$id}");
            return ['data' => $this->normalizeAnime($json['data'] ?? null)];
        });
    }

    public function episodes(int $id): JsonResponse
    {
        return $this->cached("anime:episodes:{$id}", 86400, function () use ($id) {
            $episodes = [];
            $page = 1;

            do {
                $json = $this->jikan("/anime/{$id}/episodes", ['page' => $page]);
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

                if ($hasNext) {
                    usleep(400000); // Jikan rate limit
                }
            } while ($hasNext);

            // Fallback: Jikan has no episode data (movies, OVAs, specials).
            // Generate a numbered list from the anime's total episode count.
            if (empty($episodes)) {
                $detail = $this->jikan("/anime/{$id}");
                $total  = $detail['data']['episodes'] ?? 1;
                $total  = is_numeric($total) ? (int) $total : 1;
                $title  = $detail['data']['title'] ?? '';
                $type   = strtolower($detail['data']['type'] ?? 'tv');

                for ($n = 1; $n <= max(1, $total); $n++) {
                    $label = match(true) {
                        $total === 1 && in_array($type, ['movie', 'special', 'ova', 'ona']) => $title ?: 'Watch',
                        default => "Episode {$n}",
                    };
                    $episodes[] = [
                        'number'    => $n,
                        'title'     => $label,
                        'duration'  => null,
                        'thumbnail' => null,
                        'videoUrl'  => null,
                    ];
                }
            }

            return ['data' => $episodes];
        });
    }

    public function streaming(int $id): JsonResponse
    {
        return $this->cached("anime:streaming:{$id}", 21600, function () use ($id) {
            $json = $this->jikan("/anime/{$id}/streaming");
            return ['data' => $json['data'] ?? []];
        });
    }

    public function related(int $id): JsonResponse
    {
        return $this->cached("anime:related:{$id}", 21600, function () use ($id) {
            $json = $this->jikan("/anime/{$id}/recommendations");
            $items = array_slice($json['data'] ?? [], 0, 10);
            return ['data' => array_map(fn($rec) => $this->normalizeAnime($rec['entry'] ?? null), $items)];
        });
    }

    private function normalizeAnime(?array $item): ?array
    {
        if (!$item) return null;

        $status = $item['status'] ?? '';
        $aired  = $item['aired']['prop']['from']['year'] ?? null;

        return [
            'id'       => $item['mal_id'],
            'title'    => $item['title'] ?? '',
            'subtitle' => $item['title_japanese'] ?? $item['title_english'] ?? '',
            'image'    => $item['images']['jpg']['large_image_url']
                       ?? $item['images']['jpg']['image_url']
                       ?? '',
            'genre'    => $item['genres'][0]['name']
                       ?? $item['demographics'][0]['name']
                       ?? 'Anime',
            'badge'    => $item['genres'][0]['name'] ?? 'Anime',
            'rating'   => is_numeric($item['score'] ?? null) ? (float) $item['score'] : 0,
            'episodes' => $item['episodes'] ?? '?',
            'status'   => $status === 'Currently Airing' ? 'Airing' : 'Done',
            'year'     => $item['year'] ?? $aired,
            'studio'   => $item['studios'][0]['name'] ?? '',
            'synopsis' => $item['synopsis'] ?? '',
            'members'  => $item['members'] ?? 0,
            'new'      => $status === 'Currently Airing',
            'trailerUrl' => isset($item['trailer']['embed_url'])
                ? str_replace('autoplay=1', 'autoplay=0', $item['trailer']['embed_url']) . '&enablejsapi=0'
                : null,
        ];
    }
}
