<?php

use App\Support\Database\WorldSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        WorldSchema::table('wars', function (Blueprint $table) {
            $table->integer('turns_left')->change();
        });
    }

    public function down(): void
    {
        WorldSchema::table('wars', function (Blueprint $table) {
            $table->unsignedInteger('turns_left')->change();
        });
    }
};
