<?php

use App\Support\Database\WorldSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        WorldSchema::create('trade_prices', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->integer('coal');
            $table->integer('oil');
            $table->integer('uranium');
            $table->integer('iron');
            $table->integer('bauxite');
            $table->integer('lead');
            $table->integer('gas');
            $table->integer('munitions');
            $table->integer('steel');
            $table->integer('aluminum');
            $table->integer('food');
            $table->integer('credits');
            $table->timestamps();

            $table->index('created_at', 'idx_created_at');
        });
    }

    public function down(): void
    {
        WorldSchema::dropIfExists('trade_prices');
    }
};
