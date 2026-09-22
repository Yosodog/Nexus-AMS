<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('pages', function (Blueprint $table) {
            $table->json('draft_metadata')->nullable();
            $table->json('published_metadata')->nullable();
        });

        Schema::table('page_versions', function (Blueprint $table) {
            $table->json('page_metadata')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('page_versions', function (Blueprint $table) {
            $table->dropColumn('page_metadata');
        });

        Schema::table('pages', function (Blueprint $table) {
            $table->dropColumn(['draft_metadata', 'published_metadata']);
        });
    }
};
