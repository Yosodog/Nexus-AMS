<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void {
        Schema::create('war_aid_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('nation_id');
            $table->foreignId('account_id');
            $table->string('note');
            $table->bigInteger('money')->default(0);
            $table->integer('coal')->default(0);
            $table->integer('oil')->default(0);
            $table->integer('uranium')->default(0);
            $table->integer('iron')->default(0);
            $table->integer('bauxite')->default(0);
            $table->integer('lead')->default(0);
            $table->integer('gasoline')->default(0);
            $table->integer('munitions')->default(0);
            $table->integer('steel')->default(0);
            $table->integer('aluminum')->default(0);
            $table->integer('food')->default(0);
            $table->enum('status', ['pending', 'approved', 'denied'])->default('pending');
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('denied_at')->nullable();
            $table->timestamps();

            $table->index("nation_id");
            $table->index("account_id");
            $table->index("status");
        });
    }

    public function down(): void {
        Schema::dropIfExists('war_aid_requests');
    }
};
