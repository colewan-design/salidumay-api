<?php

namespace App\Console\Commands;

use App\Models\Anime;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class EnrichAniList extends Command
{
    protected $signature = 'anime:enrich-anilist
                            {--all : Re-fetch even rows that already have an english_title}
                            {--limit=0 : Max rows to process (0 = unlimited)}';

    protected $description = 'Backfill english_title from AniList using MAL IDs';

    private const ANILIST   = 'https://graphql.anilist.co';
    private const DELAY_MS  = 600000; // 0.6s — AniList allows ~90 req/min

    private const QUERY = '
        query ($malId: Int) {
            Media(idMal: $malId, type: ANIME) {
                title { english romaji }
            }
        }
    ';

    public function handle(): int
    {
        $query = Anime::query();

        if (!$this->option('all')) {
            $query->whereNull('english_title');
        }

        $limit = (int) $this->option('limit');
        if ($limit > 0) {
            $query->limit($limit);
        }

        $rows  = $query->get(['mal_id', 'title', 'english_title']);
        $total = $rows->count();

        if ($total === 0) {
            $this->info('Nothing to enrich — all rows already have an english_title.');
            $this->line('Run with --all to re-fetch existing titles.');
            return 0;
        }

        $this->info("Enriching {$total} anime from AniList…");
        $updated = 0;
        $skipped = 0;

        foreach ($rows as $anime) {
            $result = $this->fetchAniList($anime->mal_id);

            if ($result === null) {
                $this->warn("  [{$anime->mal_id}] {$anime->title} — not found on AniList");
                $skipped++;
                usleep(self::DELAY_MS);
                continue;
            }

            $english = $result['english'] ?? $result['romaji'] ?? null;

            $anime->english_title = $english;
            $anime->save();

            $this->line("  [{$anime->mal_id}] {$anime->title} → " . ($english ?? 'null'));
            $updated++;

            usleep(self::DELAY_MS);
        }

        $this->info("Done. Updated: {$updated} | Not found: {$skipped}");
        return 0;
    }

    private function fetchAniList(int $malId): ?array
    {
        try {
            $response = Http::timeout(15)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'Accept'       => 'application/json',
                ])
                ->post(self::ANILIST, [
                    'query'     => self::QUERY,
                    'variables' => ['malId' => $malId],
                ]);

            if ($response->status() === 429) {
                $this->warn('    Rate limited — waiting 60s…');
                sleep(60);
                return $this->fetchAniList($malId);
            }

            if ($response->failed()) {
                Log::warning('EnrichAniList: AniList error', [
                    'mal_id' => $malId,
                    'status' => $response->status(),
                ]);
                return null;
            }

            return $response->json('data.Media.title');
        } catch (\Throwable $e) {
            Log::error('EnrichAniList: request failed', [
                'mal_id' => $malId,
                'error'  => $e->getMessage(),
            ]);
            return null;
        }
    }
}