<?php

use App\Support\Database\WorldSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        WorldSchema::create('treaties', function (Blueprint $table) {
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
        WorldSchema::dropIfExists('treaties');
    }
};
