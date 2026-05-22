<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Library extends Model
{
    protected $table = 'user_library';

    protected $fillable = [
        'user_id',
        'anime_id',
        'title',
        'image',
        'genre',
        'episodes',
        'rating',
    ];

    protected function casts(): array
    {
        return [
            'episodes' => 'integer',
            'rating'   => 'float',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
