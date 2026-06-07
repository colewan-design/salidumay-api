<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tv_seasons', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('series_id')->index();
            $table->unsignedSmallInteger('season_number');
            $table->string('name')->nullable();
            $table->text('overview')->nullable();
            $table->string('poster_url')->nullable();
            $table->string('air_date', 20)->nullable();
            $table->unsignedSmallInteger('episode_count')->default(0);

            $table->timestamp('scraped_at')->nullable();
            $table->timestamps();

            $table->unique(['series_id', 'season_number']);
            $table->foreign('series_id')->references('id')->on('tv_series')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tv_seasons');
    }
};
