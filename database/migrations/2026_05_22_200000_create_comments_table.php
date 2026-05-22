<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('anime_id');
            $table->text('body');
            $table->timestamps();

            $table->index('anime_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comments');
    }
};
