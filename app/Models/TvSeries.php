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
}
