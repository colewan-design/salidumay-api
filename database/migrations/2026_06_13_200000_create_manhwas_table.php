<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('manhwas', function (Blueprint $table) {
            $table->unsignedBigInteger('mal_id')->primary();
            $table->string('title');
            $table->string('title_en')->nullable();
            $table->string('title_ja')->nullable();   // Korean/Japanese original
            $table->text('image')->nullable();
            $table->string('genre')->nullable();       // Primary genre for filtering
            $table->string('badge')->nullable();
            $table->string('status')->nullable();      // Publishing / Finished / On Hiatus
            $table->unsignedInteger('chapters')->nullable();
            $table->unsignedInteger('volumes')->nullable();
            $table->smallInteger('year')->nullable();
            $table->string('author')->nullable();
            $table->text('synopsis')->nullable();
            $table->decimal('rating', 4, 2)->default(0);
            $table->unsignedInteger('members')->default(0);
            $table->boolean('is_publishing')->default(false);
            $table->boolean('in_trending')->default(false);
            $table->boolean('in_top')->default(false);
            $table->boolean('in_new')->default(false);
            $table->timestamp('scraped_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('manhwas');
    }
};
