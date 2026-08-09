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
        Schema::table('operations_work_coordination', function (Blueprint $table) {
            $table->timestamp('assignment_expires_at')->nullable()->after('assigned_at');
            $table->index(
                ['active_key', 'assignment_expires_at'],
                'operations_coord_active_expiry_idx',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('operations_work_coordination', function (Blueprint $table) {
            $table->dropIndex('operations_coord_active_expiry_idx');
            $table->dropColumn('assignment_expires_at');
        });
    }
};
