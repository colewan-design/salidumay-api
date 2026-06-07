<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TvSeason extends Model
{
    protected $fillable = [
        'series_id', 'season_number', 'name', 'overview',
        'poster_url', 'air_date', 'episode_count', 'scraped_at',
    ];

    protected $casts = [
        'scraped_at' => 'datetime',
    ];

    public function series(): BelongsTo
    {
        return $this->belongsTo(TvSeries::class, 'series_id');
    }

    public function episodes(): HasMany
    {
        return $this->hasMany(TvEpisode::class, 'season_id')->orderBy('episode_number');
    }
}
