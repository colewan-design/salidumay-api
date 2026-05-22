<?php

namespace App\Console\Commands;

use App\Models\Anime;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ScrapeAnime extends Command
{
    protected $signature   = 'anime:scrape {--force : Re-scrape even recently scraped records}';
    protected $description = 'Scrape anime metadata from Jikan and store in the database';

    private const JIKAN      = 'https://api.jikan.moe/v4';
    private const DELAY_MS   = 500000; // 0.5s between requests — Jikan allows 3 req/s
    private const PAGE_DELAY = 1200000; // 1.2s between paginated calls

    public function handle(): int
    {
        $this->info('Starting anime scrape…');

        $this->scrapeHero();
        $this->scrapeTopAiring();   // trending
        $this->scrapeSeasonal();
        $this->scrapeTop();

        $this->info('Done.');
        return 0;
    }

    private function scrapeHero(): void
    {
        $this->line('  → Hero (top airing #1)');
        $json = $this->jikan('/top/anime', ['filter' => 'airing', 'limit' => 1]);
        if ($item = $json['data'][0] ?? null) {
            $this->upsert($item, ['in_hero' => true]);
            // clear hero flag from any other row
            Anime::where('in_hero', true)
                ->where('mal_id', '!=', $item['mal_id'])
                ->update(['in_hero' => false]);
        }
        usleep(self::DELAY_MS);
    }

    private function scrapeTopAiring(): void
    {
        $this->line('  → Trending (top airing, 12)');
        $json = $this->jikan('/top/anime', ['filter' => 'airing', 'limit' => 12]);
        $ids  = [];
        foreach ($json['data'] ?? [] as $item) {
            $this->upsert($item, ['in_trending' => true]);
            $ids[] = $item['mal_id'];
            usleep(self::DELAY_MS / 4);
        }
        Anime::where('in_trending', true)->whereNotIn('mal_id', $ids)->update(['in_trending' => false]);
        usleep(self::DELAY_MS);
    }

    private function scrapeSeasonal(): void
    {
        $this->line('  → Seasonal (now, 16)');
        $json = $this->jikan('/seasons/now', ['limit' => 16]);
        $ids  = [];
        foreach ($json['data'] ?? [] as $item) {
            $this->upsert($item, ['in_seasonal' => true]);
            $ids[] = $item['mal_id'];
            usleep(self::DELAY_MS / 4);
        }
        Anime::where('in_seasonal', true)->whereNotIn('mal_id', $ids)->update(['in_seasonal' => false]);
        usleep(self::DELAY_MS);
    }

    private function scrapeTop(): void
    {
        $this->line('  → Top anime (25)');
        $json = $this->jikan('/top/anime', ['limit' => 25]);
        $ids  = [];
        foreach ($json['data'] ?? [] as $item) {
            $this->upsert($item, ['in_top' => true]);
            $ids[] = $item['mal_id'];
            usleep(self::DELAY_MS / 4);
        }
        Anime::where('in_top', true)->whereNotIn('mal_id', $ids)->update(['in_top' => false]);
    }

    private function upsert(array $item, array $extra = []): void
    {
        $malId = $item['mal_id'] ?? null;
        if (!$malId) return;

        $status = $item['status'] ?? '';

        Anime::updateOrCreate(
            ['mal_id' => $malId],
            array_merge([
                'title'       => $item['title'] ?? '',
                'subtitle'    => $item['title_japanese'] ?? $item['title_english'] ?? null,
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
            ], $extra)
        );

        $this->line("    ✓ [{$malId}] " . ($item['title'] ?? '?'));
    }

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
