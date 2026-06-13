<?php

namespace App\Console\Commands;

use App\Models\Manhwa;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ScrapeManhwa extends Command
{
    protected $signature = 'manhwa:scrape
                            {--pages=4 : Pages to scrape from /top/manga?type=manhwa (25 per page)}
                            {--full    : Scrape all available pages (slow, use once for initial seed)}';

    protected $description = 'Scrape manhwa metadata from Jikan and store in the database';

    private const JIKAN    = 'https://api.jikan.moe/v4';
    private const DELAY_MS = 700000; // 0.7s — stay within Jikan's 3 req/s limit

    public function handle(): int
    {
        $this->info('Starting manhwa scrape…');

        $this->scrapeTrending();
        $this->scrapeTopRated();
        $this->scrapeFullList();

        $this->info('Done.');
        return 0;
    }

    // ── Top 12 by popularity (members) → in_trending ────────────────────────

    private function scrapeTrending(): void
    {
        $this->line('  → Trending (top by popularity)');

        $json = $this->jikan('/manga', [
            'type'     => 'manhwa',
            'order_by' => 'popularity',
            'sort'     => 'asc',    // popularity rank 1 = most popular
            'limit'    => 12,
        ]);

        $ids = [];
        foreach ($json['data'] ?? [] as $item) {
            $this->upsert($item, ['in_trending' => true]);
            $ids[] = $item['mal_id'];
        }

        Manhwa::where('in_trending', true)->whereNotIn('mal_id', $ids)->update(['in_trending' => false]);
        usleep(self::DELAY_MS);
    }

    // ── Top 25 by score → in_top ─────────────────────────────────────────────

    private function scrapeTopRated(): void
    {
        $this->line('  → Top rated');

        $json = $this->jikan('/top/manga', [
            'type'  => 'manhwa',
            'limit' => 25,
        ]);

        $ids = [];
        foreach ($json['data'] ?? [] as $item) {
            $this->upsert($item, ['in_top' => true]);
            $ids[] = $item['mal_id'];
        }

        Manhwa::where('in_top', true)->whereNotIn('mal_id', $ids)->update(['in_top' => false]);
        usleep(self::DELAY_MS);
    }

    // ── Paginate through /top/manga to build the full catalogue ─────────────

    private function scrapeFullList(): void
    {
        $maxPages = $this->option('full') ? 999 : (int) $this->option('pages');
        $page     = 1;

        $this->line("  → Full list (up to {$maxPages} pages × 25)");

        do {
            $this->line("    page {$page}…");

            $json  = $this->jikan('/top/manga', ['type' => 'manhwa', 'page' => $page, 'limit' => 25]);
            $batch = $json['data'] ?? [];

            if (empty($batch)) break;

            foreach ($batch as $item) {
                $this->upsert($item, []);
            }

            $hasNext = $json['pagination']['has_next_page'] ?? false;
            $page++;
            usleep(self::DELAY_MS);
        } while ($hasNext && $page <= $maxPages);

        $total = Manhwa::count();
        $this->info("  ✓ {$total} manhwa total in database");
    }

    // ── Upsert one row ───────────────────────────────────────────────────────

    private function upsert(array $item, array $extra = []): void
    {
        $malId = $item['mal_id'] ?? null;
        if (!$malId) return;

        $status = $item['status'] ?? '';

        // Resolve published year from multiple possible fields
        $year = $item['published']['prop']['from']['year']
             ?? (isset($item['published']['from'])
                ? (int) substr($item['published']['from'], 0, 4)
                : null);

        // Primary author name
        $author = null;
        foreach ($item['authors'] ?? [] as $a) {
            $author = $a['name'] ?? null;
            if ($author) break;
        }

        $fields = [
            'title'         => $item['title'] ?? '',
            'title_en'      => $item['title_english'] ?? null,
            'title_ja'      => $item['title_japanese'] ?? null,
            'image'         => $item['images']['jpg']['large_image_url']
                            ?? $item['images']['jpg']['image_url']
                            ?? null,
            'genre'         => $item['genres'][0]['name']
                            ?? $item['demographics'][0]['name']
                            ?? $item['themes'][0]['name']
                            ?? 'Action',
            'badge'         => $item['genres'][0]['name']
                            ?? $item['demographics'][0]['name']
                            ?? 'Manhwa',
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
        ];

        Manhwa::updateOrCreate(['mal_id' => $malId], array_merge($fields, $extra));

        $this->line("      ✓ [{$malId}] " . ($item['title'] ?? '?'));
    }

    // ── Jikan HTTP helper (identical to ScrapeAnime) ─────────────────────────

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
            Log::error('ScrapeManhwa jikan error', ['path' => $path, 'error' => $e->getMessage()]);
            return [];
        }
    }
}
