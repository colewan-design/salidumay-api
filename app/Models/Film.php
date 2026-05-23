<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Film extends Model
{
    // TMDB ID — not auto-incremented
    protected $primaryKey  = 'id';
    public    $incrementing = false;
    protected $keyType     = 'integer';

    protected $fillable = [
        'id',
        'title',
        'original_title',
        'overview',
        'tagline',
        'status',
        'original_language',
        'adult',
        'poster_url',
        'backdrop_url',
        'release_date',
        'year',
        'rating',
        'vote_count',
        'popularity',
        'runtime',
        'genres',
        'cast',
        'director',
        'trailer_key',
        'in_popular',
        'in_trending',
        'in_top_rated',
        'in_now_playing',
        'detail_fetched',
        'scraped_at',
    ];

    protected $casts = [
        'genres'         => 'array',
        'cast'           => 'array',
        'adult'          => 'boolean',
        'in_popular'     => 'boolean',
        'in_trending'    => 'boolean',
        'in_top_rated'   => 'boolean',
        'in_now_playing' => 'boolean',
        'detail_fetched' => 'boolean',
        'rating'         => 'float',
        'popularity'     => 'float',
        'scraped_at'     => 'datetime',
    ];

    /**
     * Normalised API response — matches the shape the Vue frontend expects.
     */
    public function toApiArray(bool $withSimilar = false): array
    {
        $data = [
            'id'              => $this->id,
            'title'           => $this->title,
            'original_title'  => $this->original_title,
            'overview'        => $this->overview,
            'tagline'         => $this->tagline,
            'status'          => $this->status,
            'original_language' => $this->original_language,
            'adult'           => $this->adult,
            'image'           => $this->poster_url,
            'backdrop'        => $this->backdrop_url,
            'release_date'    => $this->release_date,
            'year'            => (string) $this->year,
            'rating'          => round((float) $this->rating, 1),
            'vote_count'      => $this->vote_count,
            'popularity'      => (float) $this->popularity,
            'runtime'         => $this->runtime,
            'genres'          => $this->genres ?? [],
            'genre'           => $this->genres[0]['name'] ?? '',
            'genre_ids'       => array_column($this->genres ?? [], 'id'),
            'cast'            => $this->cast ?? [],
            'director'        => $this->director,
            'trailer_key'     => $this->trailer_key,
        ];

        if ($withSimilar) {
            $data['similar'] = $this->similar()->map->toApiArray()->values()->all();
        }

        return $data;
    }

    /**
     * Fetch similar films from DB by overlapping genre IDs.
     */
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

    // ── Scopes ──────────────────────────────────────────────────────────────

    public function scopePopular($q)     { return $q->where('in_popular', true); }
    public function scopeTrending($q)    { return $q->where('in_trending', true); }
    public function scopeTopRated($q)    { return $q->where('in_top_rated', true); }
    public function scopeNowPlaying($q)  { return $q->where('in_now_playing', true); }
}
