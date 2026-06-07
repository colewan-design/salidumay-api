<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tv_episodes', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary(); // TMDB episode ID
            $table->unsignedBigInteger('series_id')->index();
            $table->unsignedBigInteger('season_id')->index();
            $table->unsignedSmallInteger('season_number');
            $table->unsignedSmallInteger('episode_number');
            $table->string('name')->nullable();
            $table->text('overview')->nullable();
            $table->string('still_url')->nullable();
            $table->string('air_date', 20)->nullable();
            $table->unsignedSmallInteger('runtime')->nullable();
            $table->decimal('rating', 4, 2)->default(0);

            $table->timestamp('scraped_at')->nullable();
            $table->timestamps();

            $table->foreign('series_id')->references('id')->on('tv_series')->cascadeOnDelete();
            $table->foreign('season_id')->references('id')->on('tv_seasons')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tv_episodes');
    }
};
