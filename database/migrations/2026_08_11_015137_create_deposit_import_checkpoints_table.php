<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('deposit_import_checkpoints', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('alliance_id')->unique();
            $table->unsignedBigInteger('last_scanned_id')->default(0);
            $table->timestamp('last_attempted_at')->nullable();
            $table->timestamp('last_succeeded_at')->nullable();
            $table->timestamp('last_failed_at')->nullable();
            $table->timestamp('last_imported_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });

        $primaryAllianceId = (int) config('services.pw.alliance_id', 0);
        $legacyCheckpoint = Schema::hasTable('settings')
            ? (int) (DB::table('settings')->where('key', 'last_bank_record_id')->value('value') ?? 0)
            : 0;

        if ($primaryAllianceId > 0) {
            DB::table('deposit_import_checkpoints')->insert([
                'alliance_id' => $primaryAllianceId,
                'last_scanned_id' => $legacyCheckpoint,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('deposit_import_checkpoints');
    }
};
