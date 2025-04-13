<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('treaties', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('pw_id')->unique();
            $table->dateTime('pw_date');
            $table->integer('turns_left');
            $table->unsignedInteger('alliance1_id');
            $table->unsignedInteger('alliance2_id');
            $table->string('type');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('treaties');
    }
};
