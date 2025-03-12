<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('nation_resources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('nation_id')->unique()->index()->constrained('nations')->onDelete('cascade');
            $table->float('money');
            $table->float('coal');
            $table->float('oil');
            $table->float('uranium');
            $table->float('iron');
            $table->float('bauxite');
            $table->float('lead');
            $table->float('gasoline');
            $table->float('munitions');
            $table->float('steel');
            $table->float('aluminum');
            $table->float('food');
            $table->unsignedTinyInteger('credits');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('nation_resources');
    }
};
