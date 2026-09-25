<?php

use App\Support\Database\WorldSchema;
use App\Support\Raids\RaidLootObservationMapper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Copies every stored victory and alliance-loot attack into raid_loot_events, then
 * drops the attack and nation observation tables. Target profiles are rebuilt from the
 * authoritative world tables by `raids:rebuild` after deployment.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! WorldSchema::usesPhysicalTables()) {
            return;
        }

        if (Schema::hasTable('raid_attack_observations')) {
            DB::table('raid_attack_observations')->orderBy('id')->chunkById(1000, function (Collection $rows): void {
                $this->insert($rows->map(fn (object $row): ?array => RaidLootObservationMapper::map((array) $row)));
            });
        }

        DB::table('war_attacks')
            ->whereIn('type', ['VICTORY', 'ALLIANCELOOT'])
            ->orderBy('id')
            ->chunkById(1000, function (Collection $attacks): void {
                $wars = DB::table('wars')
                    ->whereIn('id', $attacks->pluck('war_id')->unique()->all())
                    ->get(['id', 'def_id', 'war_type', 'att_alliance_id', 'def_alliance_id'])
                    ->keyBy('id');

                $this->insert($attacks->map(function (object $attack) use ($wars): ?array {
                    $war = $wars->get($attack->war_id);

                    return RaidLootObservationMapper::map((array) $attack + ['war' => $war === null ? null : (array) $war]);
                }));
            });

        Schema::dropIfExists('raid_nation_observations');
        Schema::dropIfExists('raid_attack_observations');
    }

    public function down(): void
    {
        throw new RuntimeException('This migration cannot be reversed; restore from backup.');
    }

    /**
     * @param  Collection<int, array<string, mixed>|null>  $rows
     */
    private function insert(Collection $rows): void
    {
        $rows = $rows->filter()->values();

        if ($rows->isNotEmpty()) {
            DB::table('raid_loot_events')->insertOrIgnore($rows->all());
        }
    }
};
