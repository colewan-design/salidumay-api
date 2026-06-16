<?php

namespace App\Console\Commands;

use App\Models\TvEpisode;
use App\Models\TvSeason;
use App\Models\TvSeries;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ScrapeTvSeries extends Command
{
    protected $signature = 'tv:scrape
        {--id=             : Scrape a single series by TMDB ID (fetches basic info, detail, and seasons)}
        {--pages=3         : Pages of popular / top-rated / airing-today to scrape (20 per page)}
        {--detail          : Fetch full series detail (cast, crew, trailer) for every series}
        {--detail-only     : Skip list scraping; only backfill detail for series without it}
        {--seasons         : Fetch seasons and episodes for every series that has detail}
        {--seasons-only    : Skip list and detail scraping; only fetch missing seasons/episodes}
        {--limit=50        : Max number of series to process per seasons run (prevents memory kills)}
        {--lists=          : Comma-separated categories: popular,top_rated,airing_today,trending}
        {--fresh           : Reset all category flags before scraping}';

    protected $description = 'Scrape TMDB for TV series, seasons, and episodes';

    private const IMG_BASE    = 'https://image.tmdb.org/t/p';
    private const TMDB_BASE   = 'https://api.themoviedb.org/3';
    private const REQUEST_GAP = 260; // ms — well within TMDB's 40 req/10 s limit

    private ?string $apiKey = null;
    private int $upserted   = 0;
    private int $detailed   = 0;
    private int $seasoned   = 0;
    private int $errors     = 0;

    public function handle(): int
    {
        $this->apiKey = config('services.tmdb.key');

        if (! $this->apiKey) {
            $this->error('TMDB_API_KEY is not set. Add it to your .env file.');
            return 1;
        }

        if ($singleId = $this->option('id')) {
            return $this->scrapeSingleSeries((int) $singleId);
        }

        $detailOnly  = $this->option('detail-only');
        $seasonsOnly = $this->option('seasons-only');
        $fetchDetail = $this->option('detail') || $detailOnly;
        $fetchSeasons = $this->option('seasons') || $seasonsOnly;

        if ($this->option('fresh')) {
            $this->line('  → Resetting category flags…');
            TvSeries::query()->update([
                'in_popular'      => false,
                'in_trending'     => false,
                'in_top_rated'    => false,
                'in_airing_today' => false,
            ]);
        }

        if (! $detailOnly && ! $seasonsOnly) {
            $this->scrapeListPhase();
        }

        if ($fetchDetail) {
            $this->scrapeDetailPhase();
        }

        if ($fetchSeasons) {
            $this->scrapeSeasonPhase();
        }

        $this->newLine();
        $this->info(
            "Done. Upserted: {$this->upserted}  |  Detailed: {$this->detailed}  |  " .
            "Series with seasons: {$this->seasoned}  |  Errors: {$this->errors}"
        );

        return 0;
    }

    // ── Single-series mode ───────────────────────────────────────────────────

    private function scrapeSingleSeries(int $tmdbId): int
    {
        $this->info("Scraping single series TMDB #{$tmdbId}…");

        // Basic info
        $basic = $this->tmdb("/tv/{$tmdbId}");
        if (! $basic) {
            $this->error("Could not fetch series #{$tmdbId} from TMDB.");
            return 1;
        }

        $this->upsertBasic($basic, []);

        // Full detail
        $data = $this->tmdb("/tv/{$tmdbId}", ['append_to_response' => 'credits,videos']);
        $series = TvSeries::find($tmdbId);

        if ($data && $series) {
            $series->update([
                'tagline'            => $data['tagline'] ?? $series->tagline,
                'status'             => $data['status'] ?? $series->status,
                'overview'           => $data['overview'] ?? $series->overview,
                'number_of_seasons'  => $data['number_of_seasons'] ?? $series->number_of_seasons,
                'number_of_episodes' => $data['number_of_episodes'] ?? $series->number_of_episodes,
                'genres'             => $this->buildGenres($data['genres'] ?? []),
                'cast'               => $this->buildCast($data['credits']['cast'] ?? []),
                'created_by'         => $this->buildCreatedBy($data['created_by'] ?? []),
                'trailer_key'        => $this->extractTrailerKey($data['videos']['results'] ?? []),
                'detail_fetched'     => true,
            ]);
            $this->info("  ✓ Detail saved.");
            $series->refresh();
        }

        // Seasons + episodes
        if ($series && $series->number_of_seasons) {
            for ($s = 1; $s <= $series->number_of_seasons; $s++) {
                $seasonData = $this->tmdb("/tv/{$tmdbId}/season/{$s}");
                if (! $seasonData) { usleep(self::REQUEST_GAP * 1000); continue; }

                $season = TvSeason::updateOrCreate(
                    ['series_id' => $tmdbId, 'season_number' => $s],
                    [
                        'name'          => $seasonData['name'] ?? "Season {$s}",
                        'overview'      => $seasonData['overview'] ?? null,
                        'poster_url'    => $this->img($seasonData['poster_path'] ?? null, 'w342'),
                        'air_date'      => $seasonData['air_date'] ?? null,
                        'episode_count' => count($seasonData['episodes'] ?? []),
                        'scraped_at'    => now(),
                    ]
                );

                foreach ($seasonData['episodes'] ?? [] as $ep) {
                    if (! ($ep['id'] ?? null)) continue;
                    TvEpisode::upsert(
                        [[
                            'id'             => $ep['id'],
                            'series_id'      => $tmdbId,
                            'season_id'      => $season->id,
                            'season_number'  => $s,
                            'episode_number' => $ep['episode_number'] ?? 0,
                            'name'           => $ep['name'] ?? null,
                            'overview'       => $ep['overview'] ?? null,
                            'still_url'      => $this->img($ep['still_path'] ?? null, 'w300'),
                            'air_date'       => $ep['air_date'] ?? null,
                            'runtime'        => $ep['runtime'] ?? null,
                            'rating'         => $ep['vote_average'] ?? 0,
                            'scraped_at'     => now(),
                        ]],
                        ['id'],
                        ['name', 'overview', 'still_url', 'air_date', 'runtime', 'rating', 'scraped_at']
                    );
                }

                $this->line("  S{$s}: " . count($seasonData['episodes'] ?? []) . " episode(s) saved.");
                usleep(self::REQUEST_GAP * 1000);
            }

            $series->update(['seasons_fetched' => true]);
        }

        Cache::forget("tv:detail:{$tmdbId}");
        $this->info("Done.");
        return 0;
    }

    // ── Phase 1: scrape list endpoints ──────────────────────────────────────

    private function scrapeListPhase(): void
    {
        $pages = (int) $this->option('pages');
        $only  = $this->option('lists')
            ? array_map('trim', explode(',', $this->option('lists')))
            : ['popular', 'top_rated', 'airing_today', 'trending'];

        if (in_array('popular',      $only)) $this->scrapeList('popular',      $pages, 'in_popular');
        if (in_array('top_rated',    $only)) $this->scrapeList('top_rated',    $pages, 'in_top_rated');
        if (in_array('airing_today', $only)) $this->scrapeList('airing_today', 1,      'in_airing_today');
        if (in_array('trending',     $only)) $this->scrapeList('trending',     1,      'in_trending', '/trending/tv/week');
    }

    private function scrapeList(string $label, int $pages, string $flag, string $path = null): void
    {
        $path ??= "/tv/{$label}";
        $this->info("\n[{$label}] Scraping {$pages} page(s)");

        for ($page = 1; $page <= $pages; $page++) {
            $this->line("  page {$page}/{$pages}…");
            $response = $this->tmdb($path, ['page' => $page]);

            foreach ($response['results'] ?? [] as $show) {
                $this->upsertBasic($show, [$flag => true]);
            }

            usleep(self::REQUEST_GAP * 1000);
        }
    }

    // ── Phase 2: fetch full series detail ────────────────────────────────────

    private function scrapeDetailPhase(): void
    {
        $pending = TvSeries::where('detail_fetched', false)->orderByDesc('popularity')->get();
        $total   = $pending->count();
        $this->info("\n[detail] Fetching full detail for {$total} series…");

        foreach ($pending as $series) {
            $this->line("  #{$series->id} {$series->title}");

            $data = $this->tmdb("/tv/{$series->id}", ['append_to_response' => 'credits,videos']);

            if (! $data) {
                $this->errors++;
                continue;
            }

            $series->update([
                'tagline'            => $data['tagline'] ?? $series->tagline,
                'status'             => $data['status'] ?? $series->status,
                'overview'           => $data['overview'] ?? $series->overview,
                'number_of_seasons'  => $data['number_of_seasons'] ?? $series->number_of_seasons,
                'number_of_episodes' => $data['number_of_episodes'] ?? $series->number_of_episodes,
                'genres'             => $this->buildGenres($data['genres'] ?? []),
                'cast'               => $this->buildCast($data['credits']['cast'] ?? []),
                'created_by'         => $this->buildCreatedBy($data['created_by'] ?? []),
                'trailer_key'        => $this->extractTrailerKey($data['videos']['results'] ?? []),
                'detail_fetched'     => true,
            ]);

            $this->detailed++;
            usleep(self::REQUEST_GAP * 1000);
        }
    }

    // ── Phase 3: fetch seasons and episodes ──────────────────────────────────

    private function scrapeSeasonPhase(): void
    {
        $limit   = (int) $this->option('limit');
        $pending = TvSeries::where('detail_fetched', true)
            ->where('seasons_fetched', false)
            ->orderByDesc('popularity')
            ->limit($limit > 0 ? $limit : PHP_INT_MAX)
            ->get();

        $total = $pending->count();
        $this->info("\n[seasons] Fetching seasons/episodes for {$total} series…");

        foreach ($pending as $series) {
            $this->line("  #{$series->id} {$series->title} ({$series->number_of_seasons} season(s))");

            $fetched  = 0;
            $notFound = 0;

            for ($s = 1; $s <= $series->number_of_seasons; $s++) {
                $data = $this->tmdb("/tv/{$series->id}/season/{$s}");

                if (! $data) {
                    $notFound++;
                    usleep(self::REQUEST_GAP * 1000);
                    continue;
                }

                $fetched++;

                $season = TvSeason::updateOrCreate(
                    ['series_id' => $series->id, 'season_number' => $s],
                    [
                        'name'          => $data['name'] ?? "Season {$s}",
                        'overview'      => $data['overview'] ?? null,
                        'poster_url'    => $this->img($data['poster_path'] ?? null, 'w342'),
                        'air_date'      => $data['air_date'] ?? null,
                        'episode_count' => count($data['episodes'] ?? []),
                        'scraped_at'    => now(),
                    ]
                );

                $this->line("    S{$s}: " . count($data['episodes'] ?? []) . " episode(s)");

                foreach ($data['episodes'] ?? [] as $ep) {
                    $epId = $ep['id'] ?? null;
                    if (! $epId) continue;

                    TvEpisode::upsert(
                        [[
                            'id'             => $epId,
                            'series_id'      => $series->id,
                            'season_id'      => $season->id,
                            'season_number'  => $s,
                            'episode_number' => $ep['episode_number'] ?? 0,
                            'name'           => $ep['name'] ?? null,
                            'overview'       => $ep['overview'] ?? null,
                            'still_url'      => $this->img($ep['still_path'] ?? null, 'w300'),
                            'air_date'       => $ep['air_date'] ?? null,
                            'runtime'        => $ep['runtime'] ?? null,
                            'rating'         => $ep['vote_average'] ?? 0,
                            'scraped_at'     => now(),
                        ]],
                        ['id'],
                        ['name', 'overview', 'still_url', 'air_date', 'runtime', 'rating', 'scraped_at']
                    );
                }

                usleep(self::REQUEST_GAP * 1000);
            }

            $this->errors += $notFound;

            // Mark done if at least one season was fetched, or if TMDB has no data at all
            // (all 404s). This prevents endlessly retrying shows TMDB can't serve.
            if ($fetched > 0 || $notFound === $series->number_of_seasons) {
                $series->update(['seasons_fetched' => true]);
                $this->seasoned++;

                if ($notFound > 0 && $fetched === 0) {
                    $this->warn("    ⚠ No season data on TMDB — marked done to skip future runs");
                }
            }
        }
    }

    // ── Upsert helpers ───────────────────────────────────────────────────────

    private function upsertBasic(array $show, array $flags): void
    {
        try {
            $year = isset($show['first_air_date']) && strlen($show['first_air_date']) >= 4
                ? (int) substr($show['first_air_date'], 0, 4)
                : null;

            TvSeries::upsert(
                [[
                    'id'                => $show['id'],
                    'title'             => $show['name'] ?? $show['original_name'] ?? 'Unknown',
                    'original_title'    => $show['original_name'] ?? null,
                    'overview'          => $show['overview'] ?? null,
                    'poster_url'        => $this->img($show['poster_path'] ?? null, 'w500'),
                    'backdrop_url'      => $this->img($show['backdrop_path'] ?? null, 'original'),
                    'first_air_date'    => $show['first_air_date'] ?? null,
                    'year'              => $year,
                    'rating'            => $show['vote_average'] ?? 0,
                    'vote_count'        => $show['vote_count'] ?? 0,
                    'popularity'        => $show['popularity'] ?? 0,
                    'original_language' => $show['original_language'] ?? null,
                    'genres'            => json_encode(
                        array_map(fn($id) => ['id' => $id, 'name' => ''], $show['genre_ids'] ?? [])
                    ),
                    'scraped_at' => now(),
                    ...$flags,
                ]],
                ['id'],
                [
                    'title', 'original_title', 'overview', 'poster_url', 'backdrop_url',
                    'first_air_date', 'year', 'rating', 'vote_count', 'popularity',
                    'original_language', 'scraped_at', ...array_keys($flags),
                ]
            );

            $this->upserted++;
            $this->line("    ✓ [{$show['id']}] " . ($show['name'] ?? '?'));
        } catch (\Throwable $e) {
            $this->errors++;
            Log::error("ScrapeTvSeries: upsert #{$show['id']} failed — {$e->getMessage()}");
        }
    }

    // ── Data builders ────────────────────────────────────────────────────────

    private function buildGenres(array $genres): array
    {
        return array_map(fn($g) => ['id' => $g['id'], 'name' => $g['name']], $genres);
    }

    private function buildCast(array $cast): array
    {
        return array_slice(
            array_map(fn($c) => [
                'id'        => $c['id'],
                'name'      => $c['name'],
                'character' => $c['character'] ?? '',
                'image'     => $this->img($c['profile_path'] ?? null, 'w185'),
            ], $cast),
            0, 12
        );
    }

    private function buildCreatedBy(array $creators): array
    {
        return array_map(fn($c) => [
            'id'    => $c['id'],
            'name'  => $c['name'],
            'image' => $this->img($c['profile_path'] ?? null, 'w185'),
        ], $creators);
    }

    private function extractTrailerKey(array $videos): ?string
    {
        foreach ($videos as $v) {
            if (($v['type'] ?? '') === 'Trailer' && ($v['site'] ?? '') === 'YouTube') {
                return $v['key'];
            }
        }
        return null;
    }

    // ── HTTP + image helpers ─────────────────────────────────────────────────

    private function tmdb(string $path, array $params = []): ?array
    {
        try {
            $response = Http::timeout(15)->get(
                self::TMDB_BASE . $path,
                array_merge(['api_key' => $this->apiKey], $params)
            );

            if ($response->failed()) {
                $this->warn("  ✗ TMDB {$path} returned HTTP {$response->status()}");
                $this->errors++;
                return null;
            }

            return $response->json();
        } catch (\Throwable $e) {
            $this->warn("  ✗ TMDB {$path} exception: {$e->getMessage()}");
            $this->errors++;
            return null;
        }
    }

    private function img(?string $path, string $size = 'w500'): ?string
    {
        return $path ? self::IMG_BASE . "/{$size}{$path}" : null;
    }
}
