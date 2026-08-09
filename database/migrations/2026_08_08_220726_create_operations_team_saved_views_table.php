<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('operations_team_saved_views', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id');
            $table->string('team_key', 64);
            $table->string('name', 60);
            $table->json('filters');
            $table->foreignId('created_by_user_id')->nullable();
            $table->timestamps();

            $table->foreign('created_by_user_id', 'operations_team_view_creator_fk')
                ->references('id')->on('users')->nullOnDelete();

            $table->unique('public_id', 'operations_team_view_public_unique');
            $table->unique(['team_key', 'name'], 'operations_team_view_team_name_unique');
            $table->index(['team_key', 'updated_at'], 'operations_team_view_team_updated_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('operations_team_saved_views');
    }
};
