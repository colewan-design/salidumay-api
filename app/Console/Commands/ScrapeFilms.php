<?php

namespace App\Console\Commands;

use App\Models\Film;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ScrapeFilms extends Command
{
    protected $signature = 'films:scrape
        {--pages=3      : Pages of popular / top-rated / now-playing to scrape (20 films per page)}
        {--detail       : Fetch full detail (cast, crew, trailer) for every film — slow but thorough}
        {--detail-only  : Skip list scraping; only backfill detail for films that have detail_fetched=false}
        {--fresh        : Reset all category flags before scraping so stale entries are cleared}';

    protected $description = 'Scrape TMDB for films: popular, trending, top-rated, now-playing';

    private const IMG_BASE     = 'https://image.tmdb.org/t/p';
    private const TMDB_BASE    = 'https://api.themoviedb.org/3';
    private const REQUEST_GAP  = 260; // ms between requests — well within TMDB's 40 req/10 s limit

    private string $apiKey;
    private int    $upserted  = 0;
    private int    $detailed  = 0;
    private int    $errors    = 0;

    // ── Entry point ─────────────────────────────────────────────────────────

    public function handle(): int
    {
        $this->apiKey = config('services.tmdb.key');

        if (! $this->apiKey) {
            $this->error('TMDB_API_KEY is not set. Add it to your .env file.');
            return 1;
        }

        $pages      = (int) $this->option('pages');
        $fetchDetail = $this->option('detail');
        $detailOnly  = $this->option('detail-only');
        $fresh       = $this->option('fresh');

        if ($fresh) {
            $this->line('  → Resetting category flags…');
            Film::query()->update([
                'in_popular'     => false,
                'in_trending'    => false,
                'in_top_rated'   => false,
                'in_now_playing' => false,
            ]);
        }

        if (! $detailOnly) {
            $this->scrapeList('popular',     $pages,      'in_popular');
            $this->scrapeList('trending',    1,           'in_trending', '/trending/movie/week');
            $this->scrapeList('top_rated',   $pages,      'in_top_rated');
            $this->scrapeList('now_playing', min($pages, 2), 'in_now_playing');
        }

        if ($fetchDetail || $detailOnly) {
            $this->scrapeDetails();
        }

        $this->newLine();
        $this->info("Done. Upserted: {$this->upserted}  |  Detailed: {$this->detailed}  |  Errors: {$this->errors}");
        return 0;
    }

    // ── List scraper ────────────────────────────────────────────────────────

    private function scrapeList(string $label, int $pages, string $flag, string $path = null): void
    {
        $path ??= "/movie/{$label}";
        $this->info("\n[{$label}] Scraping {$pages} page(s) → flag: {$flag}");

        for ($page = 1; $page <= $pages; $page++) {
            $this->line("  page {$page}/{$pages}…");

            $response = $this->tmdb($path, ['page' => $page]);

            if (! $response) {
                continue;
            }

            foreach ($response['results'] ?? [] as $movie) {
                $this->upsertBasic($movie, [$flag => true]);
            }

            usleep(self::REQUEST_GAP * 1000);
        }
    }

    // ── Detail scraper ──────────────────────────────────────────────────────

    private function scrapeDetails(): void
    {
        $pending = Film::where('detail_fetched', false)->orderByDesc('popularity')->get();

        $total = $pending->count();
        $this->info("\n[detail] Fetching full detail for {$total} film(s)…");

        foreach ($pending as $film) {
            $this->line("  #{$film->id} {$film->title}");

            $data = $this->tmdb("/movie/{$film->id}", [
                'append_to_response' => 'credits,videos',
            ]);

            if (! $data) {
                $this->errors++;
                continue;
            }

            $film->update([
                'runtime'        => $data['runtime'] ?? $film->runtime,
                'tagline'        => $data['tagline'] ?? $film->tagline,
                'status'         => $data['status'] ?? $film->status,
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

    // ── Upsert helpers ───────────────────────────────────────────────────────

    private function upsertBasic(array $movie, array $flags): void
    {
        try {
            $year = isset($movie['release_date']) && strlen($movie['release_date']) >= 4
                ? (int) substr($movie['release_date'], 0, 4)
                : null;

            Film::upsert(
                [[
                    'id'              => $movie['id'],
                    'title'           => $movie['title'] ?? $movie['name'] ?? 'Unknown',
                    'original_title'  => $movie['original_title'] ?? null,
                    'overview'        => $movie['overview'] ?? null,
                    'poster_url'      => $this->img($movie['poster_path'] ?? null, 'w500'),
                    'backdrop_url'    => $this->img($movie['backdrop_path'] ?? null, 'original'),
                    'release_date'    => $movie['release_date'] ?? null,
                    'year'            => $year,
                    'rating'          => $movie['vote_average'] ?? 0,
                    'vote_count'      => $movie['vote_count'] ?? 0,
                    'popularity'      => $movie['popularity'] ?? 0,
                    'adult'           => (bool) ($movie['adult'] ?? false),
                    'original_language' => $movie['original_language'] ?? null,
                    // genre_ids from list endpoints — full genre objects come via detail scrape
                    'genres'          => json_encode(
                        array_map(fn($id) => ['id' => $id, 'name' => ''], $movie['genre_ids'] ?? [])
                    ),
                    'scraped_at'      => now(),
                    ...$flags,
                ]],
                ['id'],
                [
                    'title', 'original_title', 'overview', 'poster_url', 'backdrop_url',
                    'release_date', 'year', 'rating', 'vote_count', 'popularity',
                    'adult', 'original_language', 'scraped_at',
                    ...array_keys($flags),
                ]
            );

            $this->upserted++;
        } catch (\Throwable $e) {
            $this->errors++;
            Log::error("ScrapeFilms: upsert #{$movie['id']} failed — {$e->getMessage()}");
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

    private function extractDirector(array $crew): string
    {
        foreach ($crew as $member) {
            if (($member['job'] ?? '') === 'Director') {
                return $member['name'];
            }
        }
        return '';
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
            $response = Http::timeout(15)
                ->get(self::TMDB_BASE . $path, array_merge(['api_key' => $this->apiKey], $params));

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
