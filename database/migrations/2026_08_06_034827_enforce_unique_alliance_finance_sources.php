<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const UNIQUE_INDEX = 'alliance_finance_entries_source_unique';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $duplicateIds = DB::table('alliance_finance_entries as duplicate')
            ->join('alliance_finance_entries as canonical', function ($join): void {
                $join->on('canonical.source_type', '=', 'duplicate.source_type')
                    ->on('canonical.source_id', '=', 'duplicate.source_id')
                    ->on('canonical.direction', '=', 'duplicate.direction')
                    ->on('canonical.category', '=', 'duplicate.category')
                    ->on('canonical.id', '<', 'duplicate.id');
            })
            ->whereNotNull('duplicate.source_type')
            ->whereNotNull('duplicate.source_id')
            ->distinct()
            ->pluck('duplicate.id');

        $duplicateIds->chunk(1000)->each(function (Collection $ids): void {
            DB::table('alliance_finance_entries')
                ->whereIn('id', $ids->all())
                ->delete();
        });

        Schema::table('alliance_finance_entries', function (Blueprint $table): void {
            $table->unique(
                ['source_type', 'source_id', 'direction', 'category'],
                self::UNIQUE_INDEX,
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('alliance_finance_entries', function (Blueprint $table): void {
            $table->dropUnique(self::UNIQUE_INDEX);
        });
    }
};
