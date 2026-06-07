<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tv_series', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary(); // TMDB ID

            $table->string('title');
            $table->string('original_title')->nullable();
            $table->text('overview')->nullable();
            $table->string('tagline')->nullable();
            $table->string('status')->nullable();         // Returning Series, Ended, etc.
            $table->string('original_language', 10)->nullable();

            $table->string('poster_url')->nullable();
            $table->string('backdrop_url')->nullable();

            $table->string('first_air_date', 20)->nullable();
            $table->unsignedSmallInteger('year')->nullable()->index();

            $table->decimal('rating', 4, 2)->default(0)->index();
            $table->unsignedInteger('vote_count')->default(0);
            $table->decimal('popularity', 12, 3)->default(0)->index();

            $table->unsignedSmallInteger('number_of_seasons')->default(0);
            $table->unsignedInteger('number_of_episodes')->default(0);

            $table->json('genres')->nullable();
            $table->json('cast')->nullable();
            $table->json('created_by')->nullable();
            $table->string('trailer_key')->nullable();

            $table->boolean('in_popular')->default(false)->index();
            $table->boolean('in_trending')->default(false)->index();
            $table->boolean('in_top_rated')->default(false)->index();
            $table->boolean('in_airing_today')->default(false)->index();

            $table->boolean('detail_fetched')->default(false);
            $table->boolean('seasons_fetched')->default(false);

            $table->timestamp('scraped_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tv_series');
    }
};
