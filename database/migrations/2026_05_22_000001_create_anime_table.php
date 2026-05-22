<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('anime', function (Blueprint $table) {
            $table->unsignedBigInteger('mal_id')->primary();
            $table->string('title');
            $table->string('subtitle')->nullable();
            $table->text('image')->nullable();
            $table->string('genre')->default('Anime');
            $table->string('badge')->default('Anime');
            $table->float('rating')->default(0);
            $table->string('episodes')->nullable();
            $table->string('status')->default('Done');
            $table->unsignedSmallInteger('year')->nullable();
            $table->string('studio')->nullable();
            $table->text('synopsis')->nullable();
            $table->unsignedBigInteger('members')->default(0);
            $table->boolean('is_new')->default(false);
            $table->boolean('is_airing')->default(false);
            $table->text('trailer_url')->nullable();
            $table->boolean('in_hero')->default(false);
            $table->boolean('in_trending')->default(false);
            $table->boolean('in_seasonal')->default(false);
            $table->boolean('in_top')->default(false);
            $table->timestamp('scraped_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('anime');
    }
};
