<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nation_sign_ins', function (Blueprint $table) {
            $table->date('sign_in_day')->storedAs('DATE(`created_at`)');

            $table->index(['nation_id', 'created_at'], 'nsi_nation_created_at_idx');
            $table->index(['nation_id', 'id'], 'nsi_nation_id_id_idx');
            $table->index('created_at', 'nsi_created_at_idx');
            $table->index('sign_in_day', 'nsi_sign_in_day_idx');
        });
    }

    public function down(): void
    {
        Schema::table('nation_sign_ins', function (Blueprint $table) {
            $table->dropIndex('nsi_nation_created_at_idx');
            $table->dropIndex('nsi_nation_id_id_idx');
            $table->dropIndex('nsi_created_at_idx');
            $table->dropIndex('nsi_sign_in_day_idx');
            $table->dropColumn('sign_in_day');
        });
    }
};
