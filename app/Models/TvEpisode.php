<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TvEpisode extends Model
{
    protected $primaryKey  = 'id';
    public    $incrementing = false;
    protected $keyType     = 'integer';

    protected $fillable = [
        'id', 'series_id', 'season_id', 'season_number', 'episode_number',
        'name', 'overview', 'still_url', 'air_date', 'runtime', 'rating', 'scraped_at',
    ];

    protected $casts = [
        'rating'     => 'float',
        'scraped_at' => 'datetime',
    ];

    public function series(): BelongsTo
    {
        return $this->belongsTo(TvSeries::class, 'series_id');
    }

    public function season(): BelongsTo
    {
        return $this->belongsTo(TvSeason::class, 'season_id');
    }
}
