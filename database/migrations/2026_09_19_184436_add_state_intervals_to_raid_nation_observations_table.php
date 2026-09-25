<?php

use App\Support\Database\WorldSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! WorldSchema::usesPhysicalTables() || ! Schema::hasTable('raid_nation_observations')) {
            return;
        }

        Schema::table('raid_nation_observations', function (Blueprint $table): void {
            $table->unsignedTinyInteger('current_key')->nullable()->after('nation_id');
            $table->string('state_hash', 64)->nullable()->after('current_key');
            $table->timestamp('valid_from')->nullable()->after('observed_at');
            $table->timestamp('confirmed_through')->nullable()->after('valid_from');
        });

        $table = 'raid_nation_observations';

        DB::table($table)->update([
            'valid_from' => DB::raw('observed_at'),
            'confirmed_through' => DB::raw('observed_at'),
        ]);
        $latestIds = DB::table($table)
            ->whereNotExists(function ($newer) use ($table): void {
                $newer->selectRaw('1')->from($table.' as newer')
                    ->whereColumn('newer.nation_id', $table.'.nation_id')
                    ->where(function ($date) use ($table): void {
                        $date->whereColumn('newer.observed_at', '>', $table.'.observed_at')
                            ->orWhere(function ($sameDate) use ($table): void {
                                $sameDate->whereColumn('newer.observed_at', $table.'.observed_at')
                                    ->whereColumn('newer.id', '>', $table.'.id');
                            });
                    });
            })
            ->pluck($table.'.id');
        foreach ($latestIds->chunk(1000) as $ids) {
            DB::table($table)->whereIn('id', $ids->all())->update(['current_key' => 1]);
        }

        Schema::table($table, function (Blueprint $table): void {
            $table->unique(['nation_id', 'current_key'], 'raid_nation_observations_current_unique');
            $table->index(['nation_id', 'valid_from'], 'raid_nation_observations_valid_from_index');
        });
    }

    public function down(): void
    {
        if (! WorldSchema::usesPhysicalTables() || ! Schema::hasTable('raid_nation_observations')) {
            return;
        }

        Schema::table('raid_nation_observations', function (Blueprint $table): void {
            $table->dropUnique('raid_nation_observations_current_unique');
            $table->dropIndex('raid_nation_observations_valid_from_index');
            $table->dropColumn(['current_key', 'state_hash', 'valid_from', 'confirmed_through']);
        });
    }
};
