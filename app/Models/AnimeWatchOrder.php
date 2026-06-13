<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AnimeWatchOrder extends Model
{
    protected $table = 'anime_watch_orders';

    protected $fillable = [
        'anime_id',
        'related_mal_id',
        'related_anilist_id',
        'relation_type',
        'title',
        'english_title',
        'image',
        'format',
        'episodes',
        'year',
        'status',
        'rating',
    ];

    protected $casts = [
        'anime_id'          => 'integer',
        'related_mal_id'    => 'integer',
        'related_anilist_id'=> 'integer',
        'episodes'          => 'integer',
        'year'              => 'integer',
        'rating'            => 'integer',
    ];

    // Relation types that belong in a watch order (main story + side content)
    public const WATCH_TYPES = [
        'PREQUEL', 'SEQUEL', 'SIDE_STORY', 'SPIN_OFF',
        'PARENT', 'SUMMARY', 'ALTERNATIVE', 'OTHER',
    ];

    // Display order priority for building a sensible watch list
    public const TYPE_ORDER = [
        'PREQUEL'     => 1,
        'PARENT'      => 2,
        'SEQUEL'      => 3,
        'SIDE_STORY'  => 4,
        'SPIN_OFF'    => 5,
        'SUMMARY'     => 6,
        'ALTERNATIVE' => 7,
        'OTHER'       => 8,
    ];

    public function toApiArray(): array
    {
        return [
            'id'           => $this->related_mal_id,
            'title'        => $this->english_title ?: $this->title,
            'subtitle'     => $this->title,
            'image'        => $this->image,
            'episodes'     => $this->episodes,
            'year'         => $this->year,
            'status'       => $this->status === 'FINISHED' ? 'Done' : ($this->status === 'RELEASING' ? 'Airing' : $this->status),
            'rating'       => $this->rating ? round($this->rating / 10, 1) : 0,
            'format'       => $this->format,
            'relation'     => ucfirst(strtolower(str_replace('_', ' ', $this->relation_type))),
            'relation_type'=> $this->relation_type,
        ];
    }
}
