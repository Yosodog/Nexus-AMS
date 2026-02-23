<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('taxes', function (Blueprint $table) {
            $table->dropIndex('taxes_sender_id_index');
            $table->dropIndex('taxes_receiver_id_index');

            $table->index(['sender_id', 'date'], 'taxes_sender_id_date_index');
            $table->index(['receiver_id', 'date'], 'taxes_receiver_id_date_index');
            $table->index(['receiver_id', 'day'], 'taxes_receiver_id_day_index');
            $table->index(['receiver_id', 'id'], 'taxes_receiver_id_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('taxes', function (Blueprint $table) {
            $table->dropIndex('taxes_sender_id_date_index');
            $table->dropIndex('taxes_receiver_id_date_index');
            $table->dropIndex('taxes_receiver_id_day_index');
            $table->dropIndex('taxes_receiver_id_id_index');

            $table->index('sender_id');
            $table->index('receiver_id');
        });
    }
};
