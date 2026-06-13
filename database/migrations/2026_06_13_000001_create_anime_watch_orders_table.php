<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('anime_watch_orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('anime_id');          // MAL ID of the root anime
            $table->unsignedBigInteger('related_mal_id');    // MAL ID of the related entry
            $table->unsignedInteger('related_anilist_id')->nullable();
            $table->string('relation_type', 30);             // SEQUEL, PREQUEL, SIDE_STORY, etc.
            $table->string('title');
            $table->string('english_title')->nullable();
            $table->string('image')->nullable();
            $table->string('format', 20)->nullable();        // TV, MOVIE, OVA, ONA, SPECIAL, etc.
            $table->unsignedSmallInteger('episodes')->nullable();
            $table->unsignedSmallInteger('year')->nullable();
            $table->string('status', 30)->nullable();        // FINISHED, RELEASING, etc.
            $table->unsignedTinyInteger('rating')->nullable(); // AniList score 0–100
            $table->timestamps();

            $table->unique(['anime_id', 'related_mal_id']);
            $table->index('anime_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('anime_watch_orders');
    }
};
