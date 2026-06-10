<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_views', function (Blueprint $table) {
            $table->id();
            $table->string('content_type', 10);       // 'anime' | 'film'
            $table->unsignedBigInteger('content_id');
            $table->char('ip_hash', 64);              // sha256 of IP — privacy-safe
            $table->timestamp('created_at')->useCurrent();

            // One row per IP per piece of content — duplicate inserts are ignored
            $table->unique(['content_type', 'content_id', 'ip_hash'], 'views_unique');
            $table->index(['content_type', 'content_id'], 'views_content_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_views');
    }
};
