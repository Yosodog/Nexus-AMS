<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nation_military', function (Blueprint $table) {
            $table->index('nation_id'); // Add an index for faster lookups
        });

        Schema::table('nation_resources', function (Blueprint $table) {
            $table->index('nation_id'); // Add an index for faster lookups
        });
    }

    public function down(): void
    {
        Schema::table('nation_military', function (Blueprint $table) {
            $table->dropIndex(['nation_id']);
        });

        Schema::table('nation_resources', function (Blueprint $table) {
            $table->dropIndex(['nation_id']);
        });
    }
};
