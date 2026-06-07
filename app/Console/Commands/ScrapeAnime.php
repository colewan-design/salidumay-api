<?php

namespace App\Console\Commands;

use App\Models\Anime;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ScrapeAnime extends Command
{
    protected $signature = 'anime:scrape
                            {--pages=4 : Number of pages to scrape from /top/anime (25 per page)}
                            {--full    : Scrape all available pages (slow, use once for initial seed)}';

    protected $description = 'Scrape anime metadata from Jikan and store in the database';

    private const JIKAN    = 'https://api.jikan.moe/v4';
    private const DELAY_MS = 700000; // 0.7s between requests

    public function handle(): int
    {
        $this->info('Starting anime scrape…');

        $this->scrapeFlags();   // hero, trending, seasonal, top flags
        $this->scrapeMovies();
        $this->scrapeFullList();

        $this->info('Done.');
        return 0;
    }

    // ── Set special flags (hero, trending, seasonal, in_top) ─────────────────

    private function scrapeFlags(): void
    {
        // Hero
        $this->line('  → Hero');
        $json = $this->jikan('/top/anime', ['filter' => 'airing', 'limit' => 1]);
        if ($item = $json['data'][0] ?? null) {
            $this->upsert($item, ['in_hero' => true]);
            Anime::where('in_hero', true)->where('mal_id', '!=', $item['mal_id'])->update(['in_hero' => false]);
        }
        usleep(self::DELAY_MS);

        // Trending (top airing 12)
        $this->line('  → Trending');
        $json = $this->jikan('/top/anime', ['filter' => 'airing', 'limit' => 12]);
        $ids  = [];
        foreach ($json['data'] ?? [] as $item) {
            $this->upsert($item, ['in_trending' => true]);
            $ids[] = $item['mal_id'];
        }
        Anime::where('in_trending', true)->whereNotIn('mal_id', $ids)->update(['in_trending' => false]);
        usleep(self::DELAY_MS);

        // Seasonal
        $this->line('  → Seasonal');
        $json = $this->jikan('/seasons/now', ['limit' => 25]);
        $ids  = [];
        foreach ($json['data'] ?? [] as $item) {
            $this->upsert($item, ['in_seasonal' => true]);
            $ids[] = $item['mal_id'];
        }
        Anime::where('in_seasonal', true)->whereNotIn('mal_id', $ids)->update(['in_seasonal' => false]);
        usleep(self::DELAY_MS);
    }

    // ── Scrape top movies ────────────────────────────────────────────────────

    private function scrapeMovies(): void
    {
        $maxPages = $this->option('full') ? 20 : 2; // 2 pages = 50 movies on normal run
        $page     = 1;
        $ids      = [];

        $this->line("  → Movies (top, {$maxPages} pages)");

        do {
            $json  = $this->jikan('/top/anime', ['type' => 'movie', 'page' => $page, 'limit' => 25]);
            $batch = $json['data'] ?? [];

            if (empty($batch)) break;

            foreach ($batch as $item) {
                $this->upsert($item, ['in_movies' => true, 'type' => 'Movie']);
                $ids[] = $item['mal_id'];
            }

            $hasNext = $json['pagination']['has_next_page'] ?? false;
            $page++;
            usleep(self::DELAY_MS);
        } while ($hasNext && $page <= $maxPages);

        Anime::where('in_movies', true)->whereNotIn('mal_id', $ids)->update(['in_movies' => false]);
    }

    // ── Paginate through /top/anime to build the full list ───────────────────

    private function scrapeFullList(): void
    {
        $maxPages = $this->option('full') ? 999 : (int) $this->option('pages');
        $page     = 1;
        $topIds   = [];

        $this->line("  → Full list (up to {$maxPages} pages × 25)");

        do {
            $this->line("    page {$page}…");
            $json  = $this->jikan('/top/anime', ['page' => $page, 'limit' => 25]);
            $batch = $json['data'] ?? [];

            if (empty($batch)) break;

            foreach ($batch as $item) {
                $inTop = $page === 1; // first page = top 25
                $this->upsert($item, ['in_top' => $inTop]);
                $topIds[] = $item['mal_id'];
            }

            $hasNext = $json['pagination']['has_next_page'] ?? false;
            $page++;

            usleep(self::DELAY_MS);
        } while ($hasNext && $page <= $maxPages);

        // Clear in_top flag for anything no longer in top 25
        if (!empty($topIds)) {
            Anime::where('in_top', true)
                ->whereNotIn('mal_id', array_slice($topIds, 0, 25))
                ->update(['in_top' => false]);
        }

        $total = Anime::count();
        $this->info("  ✓ {$total} anime total in database");
    }

    // ── Upsert a single anime row ────────────────────────────────────────────

    private function upsert(array $item, array $extra = []): void
    {
        $malId = $item['mal_id'] ?? null;
        if (!$malId) return;

        $status       = $item['status'] ?? '';
        $englishTitle = $item['title_english'] ?? null;

        $fields = [
            'type'        => $item['type'] ?? 'TV',
            'title'       => $item['title'] ?? '',
            'subtitle'    => $item['title_japanese'] ?? null,
            'image'       => $item['images']['jpg']['large_image_url']
                          ?? $item['images']['jpg']['image_url']
                          ?? null,
            'genre'       => $item['genres'][0]['name'] ?? $item['demographics'][0]['name'] ?? 'Anime',
            'badge'       => $item['genres'][0]['name'] ?? 'Anime',
            'rating'      => is_numeric($item['score'] ?? null) ? (float) $item['score'] : 0,
            'episodes'    => $item['episodes'] ?? null,
            'status'      => $status === 'Currently Airing' ? 'Airing' : 'Done',
            'year'        => $item['year'] ?? $item['aired']['prop']['from']['year'] ?? null,
            'studio'      => $item['studios'][0]['name'] ?? null,
            'synopsis'    => $item['synopsis'] ?? null,
            'members'     => $item['members'] ?? 0,
            'is_new'      => $status === 'Currently Airing',
            'is_airing'   => $status === 'Currently Airing',
            'trailer_url' => isset($item['trailer']['embed_url'])
                ? str_replace('autoplay=1', 'autoplay=0', $item['trailer']['embed_url']) . '&enablejsapi=0'
                : null,
            'scraped_at'  => now(),
        ];

        // Only overwrite english_title when Jikan provides one, so
        // AniList-enriched fallbacks are not wiped on subsequent scrapes.
        if ($englishTitle !== null) {
            $fields['english_title'] = $englishTitle;
        }

        Anime::updateOrCreate(['mal_id' => $malId], array_merge($fields, $extra));

        $this->line("      ✓ [{$malId}] " . ($item['title'] ?? '?'));
    }

    // ── Jikan HTTP helper ────────────────────────────────────────────────────

    private function jikan(string $path, array $params = []): array
    {
        try {
            $response = Http::timeout(20)
                ->accept('application/json')
                ->get(self::JIKAN . $path, $params);

            if ($response->status() === 429) {
                $this->warn('    Rate limited — waiting 5s…');
                sleep(5);
                return $this->jikan($path, $params);
            }

            if ($response->failed()) {
                $this->error("    Jikan error {$response->status()} on {$path}");
                return [];
            }

            return $response->json() ?? [];
        } catch (\Throwable $e) {
            $this->error("    Request failed: {$e->getMessage()}");
            Log::error('ScrapeAnime jikan error', ['path' => $path, 'error' => $e->getMessage()]);
            return [];
        }
    }
}
