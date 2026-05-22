<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('anime', function (Blueprint $table) {
            $table->string('type')->default('TV')->after('mal_id'); // TV, Movie, OVA, ONA, Special
            $table->boolean('in_movies')->default(false)->after('in_top');
        });
    }

    public function down(): void
    {
        Schema::table('anime', function (Blueprint $table) {
            $table->dropColumn(['type', 'in_movies']);
        });
    }
};
