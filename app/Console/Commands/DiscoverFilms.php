<?php

namespace App\Console\Commands;

use App\Models\Film;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DiscoverFilms extends Command
{
    protected $signature = 'films:discover
        {--years=1980-2025   : Year range to scrape, e.g. 2000-2024}
        {--min-votes=10      : Skip films with fewer votes than this}
        {--detail            : Fetch full detail (cast, crew, trailer) after list scrape}
        {--detail-only       : Skip discovery; only backfill detail_fetched=false films}
        {--dry-run           : Print pages/counts without writing to DB}';

    protected $description = 'Discover films from TMDB by year slices — bypasses the 500-page-per-list cap';

    private const TMDB_BASE   = 'https://api.themoviedb.org/3';
    private const IMG_BASE    = 'https://image.tmdb.org/t/p';
    private const REQUEST_GAP = 260; // ms — stays within TMDB's 40 req/10 s

    private ?string $apiKey  = null;
    private int     $upserted = 0;
    private int     $skipped  = 0;
    private int     $detailed = 0;
    private int     $errors   = 0;

    public function handle(): int
    {
        $this->apiKey = config('services.tmdb.key');

        if (! $this->apiKey) {
            $this->error('TMDB_API_KEY is not set in .env');
            return 1;
        }

        $detailOnly = $this->option('detail-only');
        $dryRun     = $this->option('dry-run');

        if (! $detailOnly) {
            [$fromYear, $toYear] = $this->parseYearRange($this->option('years'));
            $minVotes            = (int) $this->option('min-votes');

            $this->info("Discovering films for years {$fromYear}–{$toYear} (min votes: {$minVotes})");
            $this->newLine();

            for ($year = $toYear; $year >= $fromYear; $year--) {
                $this->discoverYear($year, $minVotes, $dryRun);
            }
        }

        if ($this->option('detail') || $detailOnly) {
            $this->backfillDetails();
        }

        $this->newLine();
        $this->info(sprintf(
            'Done.  Upserted: %d  |  Skipped (dup): %d  |  Detailed: %d  |  Errors: %d',
            $this->upserted, $this->skipped, $this->detailed, $this->errors
        ));

        return 0;
    }

    // ── Year discovery ───────────────────────────────────────────────────────

    private function discoverYear(int $year, int $minVotes, bool $dryRun): void
    {
        // Probe page 1 to get total_pages for this year
        $probe = $this->tmdbDiscover($year, $minVotes, 1);

        if (! $probe) {
            $this->warn("  [{$year}] ✗ probe failed, skipping");
            return;
        }

        $totalPages = min((int) ($probe['total_pages'] ?? 1), 500); // TMDB hard cap
        $totalItems = (int) ($probe['total_results'] ?? 0);

        $this->line("  [{$year}] {$totalItems} films across {$totalPages} pages");

        if ($dryRun) {
            return;
        }

        // Process page 1 results (already fetched)
        foreach ($probe['results'] ?? [] as $movie) {
            $this->upsertBasic($movie);
        }

        usleep(self::REQUEST_GAP * 1000);

        // Fetch remaining pages
        for ($page = 2; $page <= $totalPages; $page++) {
            $data = $this->tmdbDiscover($year, $minVotes, $page);

            if ($data) {
                foreach ($data['results'] ?? [] as $movie) {
                    $this->upsertBasic($movie);
                }
            }

            usleep(self::REQUEST_GAP * 1000);

            // Progress dot every 10 pages
            if ($page % 10 === 0) {
                $this->line("    …page {$page}/{$totalPages} (upserted: {$this->upserted})");
            }
        }
    }

    // ── Detail backfill ──────────────────────────────────────────────────────

    private function backfillDetails(): void
    {
        $pending = Film::where('detail_fetched', false)
            ->orderByDesc('popularity')
            ->get();

        $total = $pending->count();
        $this->newLine();
        $this->info("[detail] Backfilling {$total} film(s)…");

        foreach ($pending as $film) {
            $this->line("  #{$film->id} {$film->title}");

            $data = $this->tmdb("/movie/{$film->id}", ['append_to_response' => 'credits,videos']);

            if (! $data) {
                $this->errors++;
                continue;
            }

            $film->update([
                'runtime'        => $data['runtime']  ?? $film->runtime,
                'tagline'        => $data['tagline']  ?? $film->tagline,
                'status'         => $data['status']   ?? $film->status,
                'overview'       => $data['overview'] ?? $film->overview,
                'genres'         => $this->buildGenres($data['genres'] ?? []),
                'cast'           => $this->buildCast($data['credits']['cast'] ?? []),
                'director'       => $this->extractDirector($data['credits']['crew'] ?? []),
                'trailer_key'    => $this->extractTrailerKey($data['videos']['results'] ?? []),
                'detail_fetched' => true,
            ]);

            $this->detailed++;
            usleep(self::REQUEST_GAP * 1000);
        }
    }

    // ── Upsert ───────────────────────────────────────────────────────────────

    private function upsertBasic(array $movie): void
    {
        try {
            $year = isset($movie['release_date']) && strlen($movie['release_date']) >= 4
                ? (int) substr($movie['release_date'], 0, 4)
                : null;

            $existing = Film::find($movie['id']);

            Film::upsert(
                [[
                    'id'               => $movie['id'],
                    'title'            => $movie['title'] ?? $movie['name'] ?? 'Unknown',
                    'original_title'   => $movie['original_title'] ?? null,
                    'overview'         => $movie['overview'] ?? null,
                    'poster_url'       => $this->img($movie['poster_path'] ?? null, 'w500'),
                    'backdrop_url'     => $this->img($movie['backdrop_path'] ?? null, 'original'),
                    'release_date'     => $movie['release_date'] ?? null,
                    'year'             => $year,
                    'rating'           => $movie['vote_average'] ?? 0,
                    'vote_count'       => $movie['vote_count'] ?? 0,
                    'popularity'       => $movie['popularity'] ?? 0,
                    'adult'            => (bool) ($movie['adult'] ?? false),
                    'original_language'=> $movie['original_language'] ?? null,
                    'genres'           => json_encode(
                        array_map(fn($id) => ['id' => $id, 'name' => ''], $movie['genre_ids'] ?? [])
                    ),
                    'scraped_at'       => now(),
                    // Preserve existing category flags — don't overwrite with false
                    'in_popular'       => $existing?->in_popular     ?? false,
                    'in_trending'      => $existing?->in_trending    ?? false,
                    'in_top_rated'     => $existing?->in_top_rated   ?? false,
                    'in_now_playing'   => $existing?->in_now_playing ?? false,
                    'detail_fetched'   => $existing?->detail_fetched ?? false,
                ]],
                ['id'],
                [
                    'title', 'original_title', 'overview', 'poster_url', 'backdrop_url',
                    'release_date', 'year', 'rating', 'vote_count', 'popularity',
                    'adult', 'original_language', 'scraped_at',
                ]
            );

            $existing ? $this->skipped++ : $this->upserted++;
        } catch (\Throwable $e) {
            $this->errors++;
            Log::error("DiscoverFilms: upsert #{$movie['id']} failed — {$e->getMessage()}");
        }
    }

    // ── HTTP helpers ─────────────────────────────────────────────────────────

    private function tmdbDiscover(int $year, int $minVotes, int $page): ?array
    {
        return $this->tmdb('/discover/movie', [
            'sort_by'             => 'popularity.desc',
            'primary_release_year'=> $year,
            'vote_count.gte'      => $minVotes,
            'include_adult'       => 'false',
            'page'                => $page,
        ]);
    }

    private function tmdb(string $path, array $params = []): ?array
    {
        try {
            $response = Http::timeout(15)
                ->get(self::TMDB_BASE . $path, array_merge(['api_key' => $this->apiKey], $params));

            if ($response->failed()) {
                $this->warn("  ✗ TMDB {$path} → HTTP {$response->status()}");
                $this->errors++;
                return null;
            }

            return $response->json();
        } catch (\Throwable $e) {
            $this->warn("  ✗ TMDB {$path} → {$e->getMessage()}");
            $this->errors++;
            return null;
        }
    }

    private function img(?string $path, string $size = 'w500'): ?string
    {
        return $path ? self::IMG_BASE . "/{$size}{$path}" : null;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function parseYearRange(string $range): array
    {
        if (str_contains($range, '-')) {
            [$from, $to] = explode('-', $range, 2);
            return [(int) $from, (int) $to];
        }
        $y = (int) $range;
        return [$y, $y];
    }

    private function buildGenres(array $genres): array
    {
        return array_map(fn($g) => ['id' => $g['id'], 'name' => $g['name']], $genres);
    }

    private function buildCast(array $cast): array
    {
        return array_slice(array_map(fn($c) => [
            'id'        => $c['id'],
            'name'      => $c['name'],
            'character' => $c['character'] ?? '',
            'image'     => $this->img($c['profile_path'] ?? null, 'w185'),
        ], $cast), 0, 12);
    }

    private function extractDirector(array $crew): string
    {
        foreach ($crew as $member) {
            if (($member['job'] ?? '') === 'Director') return $member['name'];
        }
        return '';
    }

    private function extractTrailerKey(array $videos): ?string
    {
        foreach ($videos as $v) {
            if (($v['type'] ?? '') === 'Trailer' && ($v['site'] ?? '') === 'YouTube') return $v['key'];
        }
        return null;
    }
}