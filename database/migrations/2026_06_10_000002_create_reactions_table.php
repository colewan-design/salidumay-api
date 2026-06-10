<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('content_type', 10);       // 'anime' | 'film'
            $table->unsignedBigInteger('content_id');
            $table->string('type', 20)->default('heart');
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->unique(['user_id', 'content_type', 'content_id', 'type'], 'reactions_unique');
            $table->index(['content_type', 'content_id', 'type'], 'reactions_content_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reactions');
    }
};
