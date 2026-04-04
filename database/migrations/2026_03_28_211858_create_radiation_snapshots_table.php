<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('radiation_snapshots', function (Blueprint $table) {
            $table->id();
            $table->timestamp('snapshot_at')->index();
            $table->decimal('global', 8, 2);
            $table->decimal('north_america', 8, 2);
            $table->decimal('south_america', 8, 2);
            $table->decimal('europe', 8, 2);
            $table->decimal('africa', 8, 2);
            $table->decimal('asia', 8, 2);
            $table->decimal('australia', 8, 2);
            $table->decimal('antarctica', 8, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('radiation_snapshots');
    }
};
