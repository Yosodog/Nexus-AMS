<?php

use App\Support\Database\WorldSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        WorldSchema::table('nations', function (Blueprint $table) {
            $table->softDeletes();
        });

        Schema::table('nation_resources', function (Blueprint $table) {
            $table->softDeletes();
        });

        Schema::table('nation_military', function (Blueprint $table) {
            $table->softDeletes();
        });

        WorldSchema::table('cities', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        WorldSchema::table('nations', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });

        Schema::table('nation_resources', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });

        Schema::table('nation_military', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });

        WorldSchema::table('cities', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
