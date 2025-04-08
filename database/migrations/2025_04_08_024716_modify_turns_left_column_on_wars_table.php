<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wars', function (Blueprint $table) {
            $table->integer('turns_left')->change();
        });
    }

    public function down(): void
    {
        Schema::table('wars', function (Blueprint $table) {
            $table->unsignedInteger('turns_left')->change();
        });
    }
};
