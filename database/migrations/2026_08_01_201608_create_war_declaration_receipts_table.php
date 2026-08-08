<?php

use App\Support\Database\WorldSchema;
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
        Schema::create('war_declaration_receipts', function (Blueprint $table) {
            $table->unsignedBigInteger('war_id')->primary();
            $table->timestamps();
        });

        if (! WorldSchema::usesPhysicalTables() || ! Schema::hasTable('wars')) {
            return;
        }

        DB::table('wars')
            ->select('id')
            ->chunkById(1000, function ($wars): void {
                $timestamp = now();
                $receipts = $wars->map(fn (object $war): array => [
                    'war_id' => (int) $war->id,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ])->all();

                DB::table('war_declaration_receipts')->insertOrIgnore($receipts);
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('war_declaration_receipts');
    }
};
