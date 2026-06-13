<?php

namespace App\Console\Commands;

use App\Models\Anime;
use App\Models\AnimeWatchOrder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ScrapeWatchOrder extends Command
{
    protected $signature = 'anime:scrape-watch-order
                            {--id=0      : Only process a single anime by MAL ID}
                            {--refresh   : Re-fetch even anime that already have watch order data}
                            {--limit=0   : Max number of anime to process (0 = all)}';

    protected $description = 'Fetch anime watch order (relations) from AniList and store in DB';

    private const ANILIST  = 'https://graphql.anilist.co';
    private const DELAY_MS = 700000; // 0.7s — stays within AniList's 90 req/min limit

    private const QUERY = '
        query ($malId: Int) {
            Media(idMal: $malId, type: ANIME) {
                id
                idMal
                relations {
                    edges {
                        relationType(version: 2)
                        node {
                            id
                            idMal
                            type
                            format
                            title { romaji english }
                            coverImage { large }
                            episodes
                            startDate { year }
                            status
                            averageScore
                        }
                    }
                }
            }
        }
    ';

    public function handle(): int
    {
        $singleId = (int) $this->option('id');
        $refresh  = $this->option('refresh');
        $limit    = (int) $this->option('limit');

        // Single-ID mode
        if ($singleId > 0) {
            $anime = Anime::find($singleId);
            if (!$anime) {
                $this->error("Anime with MAL ID {$singleId} not found in DB.");
                return 1;
            }
            $this->processAnime($anime, $refresh);
            return 0;
        }

        // Bulk mode: get all anime IDs in DB
        $query = Anime::query()->select('mal_id', 'title');

        if (!$refresh) {
            // Skip anime that already have watch order rows
            $alreadyDone = AnimeWatchOrder::distinct()->pluck('anime_id')->toArray();
            if (!empty($alreadyDone)) {
                $query->whereNotIn('mal_id', $alreadyDone);
            }
        }

        if ($limit > 0) {
            $query->limit($limit);
        }

        $rows  = $query->get();
        $total = $rows->count();

        if ($total === 0) {
            $this->info('All anime already have watch order data. Use --refresh to re-fetch.');
            return 0;
        }

        $this->info("Scraping watch order for {$total} anime from AniList…");
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $saved = 0;
        $none  = 0;

        foreach ($rows as $anime) {
            $count = $this->processAnime($anime, $refresh);
            if ($count > 0) $saved++;
            else $none++;

            $bar->advance();
            usleep(self::DELAY_MS);
        }

        $bar->finish();
        $this->newLine();
        $this->info("Done. With relations: {$saved} | No relations found: {$none}");

        return 0;
    }

    private function processAnime(Anime $anime, bool $refresh): int
    {
        $data = $this->fetchRelations($anime->mal_id);

        if ($data === null) {
            $this->newLine();
            $this->warn("  [{$anime->mal_id}] {$anime->title} — not found on AniList or request failed");
            return 0;
        }

        $edges = $data['relations']['edges'] ?? [];
        $count = 0;

        if ($refresh) {
            AnimeWatchOrder::where('anime_id', $anime->mal_id)->delete();
        }

        foreach ($edges as $edge) {
            $node         = $edge['node'] ?? [];
            $relationType = $edge['relationType'] ?? '';

            // Only store anime entries with recognised relation types
            if (($node['type'] ?? '') !== 'ANIME') continue;
            if (!in_array($relationType, AnimeWatchOrder::WATCH_TYPES)) continue;
            if (empty($node['idMal'])) continue;

            AnimeWatchOrder::updateOrCreate(
                [
                    'anime_id'       => $anime->mal_id,
                    'related_mal_id' => $node['idMal'],
                ],
                [
                    'related_anilist_id' => $node['id'] ?? null,
                    'relation_type'      => $relationType,
                    'title'              => $node['title']['romaji'] ?? '',
                    'english_title'      => $node['title']['english'] ?? null,
                    'image'              => $node['coverImage']['large'] ?? null,
                    'format'             => $node['format'] ?? null,
                    'episodes'           => $node['episodes'] ?? null,
                    'year'               => $node['startDate']['year'] ?? null,
                    'status'             => $node['status'] ?? null,
                    'rating'             => $node['averageScore'] ?? null,
                ]
            );

            $count++;
        }

        return $count;
    }

    private function fetchRelations(int $malId): ?array
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
                $this->newLine();
                $this->warn('  Rate limited by AniList — waiting 60s…');
                sleep(60);
                return $this->fetchRelations($malId);
            }

            if ($response->failed()) {
                Log::warning('ScrapeWatchOrder: AniList error', [
                    'mal_id' => $malId,
                    'status' => $response->status(),
                ]);
                return null;
            }

            $media = $response->json('data.Media');
            return $media ?: null;

        } catch (\Throwable $e) {
            Log::error('ScrapeWatchOrder: request failed', [
                'mal_id' => $malId,
                'error'  => $e->getMessage(),
            ]);
            return null;
        }
    }
}
