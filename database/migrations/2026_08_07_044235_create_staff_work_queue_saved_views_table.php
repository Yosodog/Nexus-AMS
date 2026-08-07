<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_work_queue_saved_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('public_id')->unique();
            $table->string('name', 60);
            $table->json('filters');
            $table->timestamps();

            $table->index(['user_id', 'updated_at'], 'staff_work_queue_saved_views_user_updated_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_work_queue_saved_views');
    }
};
