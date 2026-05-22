<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_library', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('anime_id');
            $table->string('title');
            $table->string('image')->nullable();
            $table->string('genre')->nullable();
            $table->unsignedSmallInteger('episodes')->nullable();
            $table->decimal('rating', 4, 2)->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'anime_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_library');
    }
};
