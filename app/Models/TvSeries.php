<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TvSeries extends Model
{
    protected $table     = 'tv_series';
    protected $primaryKey = 'id';
    public    $incrementing = false;
    protected $keyType    = 'integer';

    protected $fillable = [
        'id', 'title', 'original_title', 'overview', 'tagline', 'status',
        'original_language', 'poster_url', 'backdrop_url',
        'first_air_date', 'year', 'rating', 'vote_count', 'popularity',
        'number_of_seasons', 'number_of_episodes',
        'genres', 'cast', 'created_by', 'trailer_key',
        'in_popular', 'in_trending', 'in_top_rated', 'in_airing_today',
        'detail_fetched', 'seasons_fetched', 'scraped_at',
    ];

    protected $casts = [
        'genres'          => 'array',
        'cast'            => 'array',
        'created_by'      => 'array',
        'in_popular'      => 'boolean',
        'in_trending'     => 'boolean',
        'in_top_rated'    => 'boolean',
        'in_airing_today' => 'boolean',
        'detail_fetched'  => 'boolean',
        'seasons_fetched' => 'boolean',
        'rating'          => 'float',
        'popularity'      => 'float',
        'scraped_at'      => 'datetime',
    ];

    public function seasons(): HasMany
    {
        return $this->hasMany(TvSeason::class, 'series_id')->orderBy('season_number');
    }

    public function episodes(): HasMany
    {
        return $this->hasMany(TvEpisode::class, 'series_id');
    }

    // ── Scopes ──────────────────────────────────────────────────────────────

    public function scopePopular($q)     { return $q->where('in_popular', true); }
    public function scopeTrending($q)    { return $q->where('in_trending', true); }
    public function scopeTopRated($q)    { return $q->where('in_top_rated', true); }
    public function scopeAiringToday($q) { return $q->where('in_airing_today', true); }

    // ── API response ─────────────────────────────────────────────────────────

    public function toApiArray(bool $withSeasons = false): array
    {
        $data = [
            'id'                  => $this->id,
            'title'               => $this->title,
            'original_title'      => $this->original_title,
            'overview'            => $this->overview,
            'tagline'             => $this->tagline,
            'status'              => $this->status,
            'original_language'   => $this->original_language,
            'image'               => $this->poster_url,
            'backdrop'            => $this->backdrop_url,
            'first_air_date'      => $this->first_air_date,
            'year'                => (string) $this->year,
            'rating'              => round((float) $this->rating, 1),
            'vote_count'          => $this->vote_count,
            'popularity'          => (float) $this->popularity,
            'number_of_seasons'   => $this->number_of_seasons,
            'number_of_episodes'  => $this->number_of_episodes,
            'genres'              => $this->genres ?? [],
            'genre'               => $this->genres[0]['name'] ?? '',
            'genre_ids'           => array_column($this->genres ?? [], 'id'),
            'cast'                => $this->cast ?? [],
            'created_by'          => $this->created_by ?? [],
            'trailer_key'         => $this->trailer_key,
        ];

        if ($withSeasons) {
            $data['seasons'] = $this->seasons->map(fn ($s) => [
                'id'             => $s->id,
                'season_number'  => $s->season_number,
                'name'           => $s->name,
                'overview'       => $s->overview,
                'poster_url'     => $s->poster_url,
                'air_date'       => $s->air_date,
                'episode_count'  => $s->episode_count,
                'episodes'       => $s->episodes->map(fn ($e) => [
                    'id'             => $e->id,
                    'episode_number' => $e->episode_number,
                    'name'           => $e->name,
                    'overview'       => $e->overview,
                    'still_url'      => $e->still_url,
                    'air_date'       => $e->air_date,
                    'runtime'        => $e->runtime,
                    'rating'         => round((float) $e->rating, 1),
                ])->values()->all(),
            ])->values()->all();

            $data['similar'] = $this->similar()->map->toApiArray()->values()->all();
        }

        return $data;
    }

    public function similar(int $limit = 12)
    {
        $genreIds = array_column($this->genres ?? [], 'id');

        if (empty($genreIds)) {
            return collect();
        }

        $query = static::where('id', '!=', $this->id)
            ->where('rating', '>=', 5.0)
            ->orderByDesc('popularity');

        foreach ($genreIds as $gid) {
            $query->orWhereJsonContains('genres', ['id' => $gid]);
        }

        return $query->limit($limit)->get();
    }
}
