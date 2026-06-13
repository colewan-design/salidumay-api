<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\HandlesEngagement;
use App\Models\Manhwa;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class ManhwaController extends Controller
{
    use HandlesEngagement;

    private const JIKAN = 'https://api.jikan.moe/v4';

    // ── Lists ────────────────────────────────────────────────────────────────

    public function trending(): JsonResponse
    {
        $list = Manhwa::where('in_trending', true)
            ->orderByDesc('members')
            ->get()
            ->map->toApiArray()
            ->values();

        return response()->json(['data' => $list]);
    }

    public function top(): JsonResponse
    {
        $list = Manhwa::where('in_top', true)
            ->orderByDesc('rating')
            ->limit(10)
            ->get()
            ->map->toApiArray()
            ->values();

        return response()->json(['data' => $list]);
    }

    public function newReleases(): JsonResponse
    {
        $list = Manhwa::where('in_new', true)
            ->orderByDesc('members')
            ->limit(24)
            ->get()
            ->map->toApiArray()
            ->values();

        return response()->json(['data' => $list]);
    }

    public function rankings(Request $request): JsonResponse
    {
        $page    = max(1, (int) $request->query('page', 1));
        $perPage = 25;

        $query = Manhwa::orderByDesc('members');
        $total = $query->count();
        $items = $query->offset(($page - 1) * $perPage)->limit($perPage)->get()->map->toApiArray()->values();

        return response()->json([
            'data'       => $items,
            'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'has_next' => ($page * $perPage) < $total],
        ]);
    }

    public function genres(): JsonResponse
    {
        $data = Cache::remember('manhwa:genres', 86400, function () {
            $response = Http::timeout(15)
                ->accept('application/json')
                ->get(self::JIKAN . '/genres/manga');

            if ($response->failed()) return [];

            // Filter to genres relevant to manhwa (exclude hentai/doujin demographic tags)
            $skip = ['Doujinshi', 'Hentai', 'Erotica'];
            $genres = array_filter(
                $response->json()['data'] ?? [],
                fn($g) => !in_array($g['name'], $skip)
            );

            return array_values(array_map(
                fn($g) => ['name' => $g['name'], 'id' => $g['mal_id']],
                array_slice(array_values($genres), 0, 24)
            ));
        });

        return response()->json(['data' => $data]);
    }

    public function byGenre(Request $request, string $genre): JsonResponse
    {
        $page    = max(1, (int) $request->query('page', 1));
        $perPage = 24;

        $query = Manhwa::where('genre', $genre)->orderByDesc('members');
        $total = $query->count();
        $items = $query->offset(($page - 1) * $perPage)->limit($perPage)->get()->map->toApiArray()->values();

        return response()->json([
            'data'       => $items,
            'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'has_next' => ($page * $perPage) < $total],
        ]);
    }

    // ── Search ───────────────────────────────────────────────────────────────

    public function search(Request $request): JsonResponse
    {
        $query = trim($request->query('q', ''));
        if ($query === '') return response()->json(['data' => []]);

        // DB first
        $local = Manhwa::where('title', 'like', "%{$query}%")
            ->orWhere('title_en', 'like', "%{$query}%")
            ->orderByDesc('members')
            ->limit(25)
            ->get()
            ->map->toApiArray()
            ->values();

        if ($local->isNotEmpty()) {
            return response()->json(['data' => $local]);
        }

        // Fall back to Jikan
        $key  = 'manhwa:search:' . md5(strtolower($query));
        $data = Cache::remember($key, 1800, function () use ($query) {
            $response = Http::timeout(15)
                ->accept('application/json')
                ->get(self::JIKAN . '/manga', ['q' => $query, 'type' => 'manhwa', 'limit' => 25, 'sfw' => 'true']);

            if ($response->failed()) return [];

            return array_map([$this, 'normalizeManhwa'], $response->json()['data'] ?? []);
        });

        return response()->json(['data' => $data]);
    }

    // ── Detail ───────────────────────────────────────────────────────────────

    public function detail(Request $request, int $id): JsonResponse
    {
        $manhwa = Manhwa::find($id);

        if ($manhwa) {
            $data = $manhwa->toApiArray();
        } else {
            $key  = "manhwa:detail:{$id}";
            $data = Cache::remember($key, 21600, function () use ($id) {
                $response = Http::timeout(15)
                    ->accept('application/json')
                    ->get(self::JIKAN . "/manga/{$id}");

                if ($response->failed()) return null;

                $item = $response->json()['data'] ?? null;
                if ($item) {
                    $this->persistFromJikan($id, $item);
                }

                return $item ? $this->normalizeManhwa($item) : null;
            });
        }

        if (is_array($data)) {
            $data += $this->engagementData('manhwa', $id);
        }

        return response()->json(['data' => $data]);
    }

    // ── Engagement ───────────────────────────────────────────────────────────

    public function view(Request $request, int $id): JsonResponse
    {
        return $this->recordView($request, 'manhwa', $id);
    }

    public function react(Request $request, int $id): JsonResponse
    {
        return $this->toggleReaction($request, 'manhwa', $id);
    }

    // ── Private helpers ──────────────────────────────────────────────────────

    private function persistFromJikan(int $id, array $item): void
    {
        $status = $item['status'] ?? '';
        $year   = $item['published']['prop']['from']['year']
               ?? (isset($item['published']['from']) ? (int) substr($item['published']['from'], 0, 4) : null);

        $author = null;
        foreach ($item['authors'] ?? [] as $a) {
            $author = $a['name'] ?? null;
            if ($author) break;
        }

        Manhwa::updateOrCreate(['mal_id' => $id], [
            'title'         => $item['title'] ?? '',
            'title_en'      => $item['title_english'] ?? null,
            'title_ja'      => $item['title_japanese'] ?? null,
            'image'         => $item['images']['jpg']['large_image_url'] ?? $item['images']['jpg']['image_url'] ?? null,
            'genre'         => $item['genres'][0]['name'] ?? $item['demographics'][0]['name'] ?? 'Action',
            'badge'         => $item['genres'][0]['name'] ?? 'Manhwa',
            'status'        => $status ?: null,
            'chapters'      => is_numeric($item['chapters'] ?? null) ? (int) $item['chapters'] : null,
            'volumes'       => is_numeric($item['volumes']  ?? null) ? (int) $item['volumes']  : null,
            'year'          => $year,
            'author'        => $author,
            'synopsis'      => $item['synopsis'] ?? null,
            'rating'        => is_numeric($item['score'] ?? null) ? (float) $item['score'] : 0,
            'members'       => $item['members'] ?? 0,
            'is_publishing' => $status === 'Publishing',
            'in_new'        => $status === 'Publishing',
            'scraped_at'    => now(),
        ]);
    }

    private function normalizeManhwa(?array $item): ?array
    {
        if (!$item) return null;

        $status = $item['status'] ?? '';
        $year   = $item['published']['prop']['from']['year']
               ?? (isset($item['published']['from']) ? (int) substr($item['published']['from'], 0, 4) : null);

        $author = null;
        foreach ($item['authors'] ?? [] as $a) {
            $author = $a['name'] ?? null;
            if ($author) break;
        }

        return [
            'id'           => $item['mal_id'],
            'title'        => $item['title'] ?? '',
            'titleEn'      => $item['title_english'] ?? null,
            'titleJa'      => $item['title_japanese'] ?? null,
            'image'        => $item['images']['jpg']['large_image_url'] ?? $item['images']['jpg']['image_url'] ?? '',
            'genre'        => $item['genres'][0]['name'] ?? $item['demographics'][0]['name'] ?? 'Action',
            'badge'        => $item['genres'][0]['name'] ?? 'Manhwa',
            'status'       => $status ?: null,
            'chapters'     => is_numeric($item['chapters'] ?? null) ? (int) $item['chapters'] : null,
            'volumes'      => is_numeric($item['volumes']  ?? null) ? (int) $item['volumes']  : null,
            'year'         => $year,
            'author'       => $author,
            'synopsis'     => $item['synopsis'] ?? '',
            'rating'       => is_numeric($item['score'] ?? null) ? (float) $item['score'] : 0,
            'members'      => $item['members'] ?? 0,
            'isPublishing' => $status === 'Publishing',
        ];
    }
}
