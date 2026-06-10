<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContentView extends Model
{
    public $timestamps = false;

    protected $fillable = ['content_type', 'content_id', 'ip_hash', 'created_at'];

    protected $casts = ['created_at' => 'datetime'];
}
