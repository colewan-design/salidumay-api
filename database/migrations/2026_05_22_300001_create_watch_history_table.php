<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('watch_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('anime_id');
            $table->string('title');
            $table->string('image')->nullable();
            $table->string('genre')->nullable();
            $table->unsignedSmallInteger('episode');
            $table->timestamp('watched_at')->useCurrent();
            $table->timestamps();

            $table->unique(['user_id', 'anime_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('watch_history');
    }
};
