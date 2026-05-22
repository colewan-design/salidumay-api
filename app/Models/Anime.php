<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Anime extends Model
{
    protected $table = 'anime';
    protected $primaryKey = 'mal_id';
    public $incrementing = false;

    protected $fillable = [
        'mal_id', 'type', 'title', 'subtitle', 'english_title', 'image', 'genre', 'badge',
        'rating', 'episodes', 'status', 'year', 'studio', 'synopsis',
        'members', 'is_new', 'is_airing', 'trailer_url',
        'in_hero', 'in_trending', 'in_seasonal', 'in_top', 'in_movies', 'scraped_at',
    ];

    protected $casts = [
        'rating'      => 'float',
        'members'     => 'integer',
        'is_new'      => 'boolean',
        'is_airing'   => 'boolean',
        'in_hero'     => 'boolean',
        'in_trending' => 'boolean',
        'in_seasonal' => 'boolean',
        'in_top'      => 'boolean',
        'scraped_at'  => 'datetime',
    ];

    public function toApiArray(): array
    {
        return [
            'id'           => $this->mal_id,
            'title'        => $this->title,
            'subtitle'     => $this->subtitle,
            'englishTitle' => $this->english_title,
            'image'      => $this->image,
            'genre'      => $this->genre,
            'badge'      => $this->badge,
            'rating'     => $this->rating,
            'episodes'   => $this->episodes,
            'status'     => $this->status,
            'year'       => $this->year,
            'studio'     => $this->studio,
            'synopsis'   => $this->synopsis,
            'members'    => $this->members,
            'new'        => $this->is_new,
            'trailerUrl' => $this->trailer_url,
        ];
    }
}
