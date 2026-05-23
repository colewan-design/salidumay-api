<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('films', function (Blueprint $table) {
            // TMDB ID as primary key — no auto-increment
            $table->unsignedBigInteger('id')->primary();

            // Core metadata
            $table->string('title');
            $table->string('original_title')->nullable();
            $table->text('overview')->nullable();
            $table->string('tagline')->nullable();
            $table->string('status')->nullable();          // Released, In Production, etc.
            $table->string('original_language', 10)->nullable();
            $table->boolean('adult')->default(false);

            // Images (full TMDB CDN URLs stored directly)
            $table->string('poster_url')->nullable();
            $table->string('backdrop_url')->nullable();

            // Release info
            $table->string('release_date', 20)->nullable();
            $table->unsignedSmallInteger('year')->nullable()->index();

            // Ratings
            $table->decimal('rating', 4, 2)->default(0)->index();
            $table->unsignedInteger('vote_count')->default(0);
            $table->decimal('popularity', 12, 3)->default(0)->index();

            // Film-specific
            $table->unsignedSmallInteger('runtime')->nullable(); // minutes

            // JSON columns
            $table->json('genres')->nullable();   // [{id, name}, ...]
            $table->json('cast')->nullable();     // [{id, name, character, image}, ...]
            $table->string('director')->nullable();
            $table->string('trailer_key')->nullable(); // YouTube video key

            // Scraper flags — which list(s) each film belongs to
            $table->boolean('in_popular')->default(false)->index();
            $table->boolean('in_trending')->default(false)->index();
            $table->boolean('in_top_rated')->default(false)->index();
            $table->boolean('in_now_playing')->default(false)->index();

            // Tracks whether the full /movie/{id} detail call has been done
            $table->boolean('detail_fetched')->default(false);

            $table->timestamp('scraped_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('films');
    }
};
