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
        Schema::table('nation_accounts', function (Blueprint $table) {
            if (Schema::hasColumn('nation_accounts', 'current_inactivity_event_id')) {
                $table->dropForeign(['current_inactivity_event_id']);
                $table->dropColumn('current_inactivity_event_id');
            }

            if (Schema::hasColumn('nation_accounts', 'is_inactive')) {
                $table->dropColumn('is_inactive');
            }

            if (Schema::hasColumn('nation_accounts', 'inactive_since_at')) {
                $table->dropColumn('inactive_since_at');
            }

            if (Schema::hasColumn('nation_accounts', 'last_pw_last_active_at')) {
                $table->dropColumn('last_pw_last_active_at');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('nation_accounts', function (Blueprint $table) {
            $table->boolean('is_inactive')->default(false);
            $table->timestamp('inactive_since_at')->nullable();
            $table->timestamp('last_pw_last_active_at')->nullable();
            $table->unsignedBigInteger('current_inactivity_event_id')->nullable();

            $table->foreign('current_inactivity_event_id')
                ->references('id')
                ->on('inactivity_events')
                ->nullOnDelete();
        });
    }
};
