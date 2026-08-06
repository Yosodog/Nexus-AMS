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
        Schema::table('tax_import_checkpoints', function (Blueprint $table): void {
            $table->timestamp('last_attempted_at')->nullable()->after('last_scanned_id');
            $table->timestamp('last_succeeded_at')->nullable()->after('last_attempted_at');
            $table->timestamp('last_failed_at')->nullable()->after('last_succeeded_at');
            $table->timestamp('last_imported_at')->nullable()->after('last_failed_at');
            $table->text('last_error')->nullable()->after('last_imported_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tax_import_checkpoints', function (Blueprint $table): void {
            $table->dropColumn([
                'last_attempted_at',
                'last_succeeded_at',
                'last_failed_at',
                'last_imported_at',
                'last_error',
            ]);
        });
    }
};
