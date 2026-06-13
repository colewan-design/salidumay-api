<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Manhwa extends Model
{
    protected $table      = 'manhwas';
    protected $primaryKey = 'mal_id';
    public    $incrementing = false;

    protected $fillable = [
        'mal_id', 'title', 'title_en', 'title_ja', 'image',
        'genre', 'badge', 'status', 'chapters', 'volumes',
        'year', 'author', 'synopsis', 'rating', 'members',
        'is_publishing', 'in_trending', 'in_top', 'in_new', 'scraped_at',
    ];

    protected $casts = [
        'rating'        => 'float',
        'members'       => 'integer',
        'chapters'      => 'integer',
        'volumes'       => 'integer',
        'is_publishing' => 'boolean',
        'in_trending'   => 'boolean',
        'in_top'        => 'boolean',
        'in_new'        => 'boolean',
        'scraped_at'    => 'datetime',
    ];

    public function toApiArray(): array
    {
        return [
            'id'           => $this->mal_id,
            'title'        => $this->title,
            'titleEn'      => $this->title_en,
            'titleJa'      => $this->title_ja,
            'image'        => $this->image,
            'genre'        => $this->genre,
            'badge'        => $this->badge,
            'status'       => $this->status,
            'chapters'     => $this->chapters,
            'volumes'      => $this->volumes,
            'year'         => $this->year,
            'author'       => $this->author,
            'synopsis'     => $this->synopsis,
            'rating'       => $this->rating,
            'members'      => $this->members,
            'isPublishing' => $this->is_publishing,
        ];
    }
}
