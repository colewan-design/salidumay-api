<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Reaction extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'content_type', 'content_id', 'type'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
