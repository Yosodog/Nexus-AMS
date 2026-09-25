<?php

use App\Support\Raids\RaidPredictionRowMapper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var list<string> */
    private const DROPPED_COLUMNS = [
        'stockpile_snapshot',
        'provenance',
        'frozen_payload',
        'simulation_payload',
        'scenarios',
        'conservative_net',
        'evaluation_status',
        'evaluated_at',
    ];

    /** @var list<string> */
    private const ADDED_COLUMNS = [
        'expected_net_low',
        'expected_net_high',
        'win_probability',
        'victory_probability',
        'expected_attacks',
        'confidence',
        'finder_rank',
        'finder_expected_net',
        'finder_shown_at',
    ];

    public function up(): void
    {
        Schema::table('raid_predictions', function (Blueprint $table): void {
            $table->decimal('expected_net_low', 20, 2)->nullable()->after('expected_net');
            $table->decimal('expected_net_high', 20, 2)->nullable()->after('expected_net_low');
            $table->decimal('win_probability', 6, 4)->nullable()->after('duration_hours');
            $table->decimal('victory_probability', 6, 4)->nullable()->after('win_probability');
            $table->unsignedSmallInteger('expected_attacks')->nullable()->after('victory_probability');
            $table->string('confidence', 16)->nullable()->after('expected_attacks');
            $table->unsignedSmallInteger('finder_rank')->nullable()->after('cost_resources');
            $table->decimal('finder_expected_net', 20, 2)->nullable()->after('finder_rank');
            $table->timestamp('finder_shown_at')->nullable()->after('finder_expected_net');
        });

        DB::table('raid_predictions')->orderBy('id')->chunkById(200, function (Collection $rows): void {
            foreach ($rows as $row) {
                DB::table('raid_predictions')
                    ->where('id', $row->id)
                    ->update(RaidPredictionRowMapper::map((array) $row));
            }
        });

        Schema::table('raid_predictions', function (Blueprint $table): void {
            $table->dropIndex('raid_predictions_capture_evaluation_index');
        });

        Schema::table('raid_predictions', function (Blueprint $table): void {
            $table->dropColumn(self::DROPPED_COLUMNS);
            $table->index('capture_status', 'raid_predictions_capture_index');
        });
    }

    public function down(): void
    {
        Schema::table('raid_predictions', function (Blueprint $table): void {
            $table->dropIndex('raid_predictions_capture_index');
            $table->string('evaluation_status', 24)->default('complete');
            $table->json('stockpile_snapshot')->nullable();
            $table->json('provenance')->nullable();
            $table->json('frozen_payload')->nullable();
            $table->decimal('conservative_net', 20, 2)->nullable();
            $table->json('scenarios')->nullable();
            $table->json('simulation_payload')->nullable();
            $table->timestamp('evaluated_at')->nullable();
            $table->dropColumn(self::ADDED_COLUMNS);
        });

        Schema::table('raid_predictions', function (Blueprint $table): void {
            $table->index(['capture_status', 'evaluation_status'], 'raid_predictions_capture_evaluation_index');
        });
    }
};
