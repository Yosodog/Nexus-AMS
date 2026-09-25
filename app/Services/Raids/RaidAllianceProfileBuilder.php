<?php

namespace App\Services\Raids;

use App\Models\Alliance;
use App\Models\RaidAllianceProfile;
use App\Models\War;
use Illuminate\Support\Facades\DB;

/**
 * Maintains alliance counter rates from recent raid wars and their counter-declarations.
 */
final class RaidAllianceProfileBuilder
{
    /**
     * @return int alliance profiles written
     */
    public function refreshCounterRates(): int
    {
        $now = now();
        $windowStart = $now->copy()->subDays((int) config('raids.counter.window_days'));
        $priorCountered = (float) config('raids.counter.prior_countered');
        $priorUncountered = (float) config('raids.counter.prior_uncountered');
        $table = (new War)->getTable();
        $counterDeadline = DB::connection((new War)->getConnectionName())->getDriverName() === 'sqlite'
            ? "datetime({$table}.date, '+48 hours')"
            : "DATE_ADD({$table}.date, INTERVAL 48 HOUR)";

        $stats = War::query()
            ->selectRaw("{$table}.def_alliance_id as alliance_id")
            ->selectRaw('COUNT(*) as received')
            ->selectRaw(
                "SUM(CASE WHEN EXISTS (SELECT 1 FROM {$table} AS counters"
                ." WHERE counters.def_id = {$table}.att_id"
                ." AND counters.att_alliance_id = {$table}.def_alliance_id"
                ." AND counters.date >= {$table}.date"
                ." AND counters.date <= {$counterDeadline}) THEN 1 ELSE 0 END) as countered",
            )
            ->where('war_type', 'RAID')
            ->where('def_alliance_id', '>', 0)
            ->where(fn ($query) => $query->whereNull('def_alliance_position')->orWhere('def_alliance_position', '!=', 'APPLICANT'))
            ->where('date', '>=', $windowStart)
            ->groupBy('def_alliance_id')
            ->get()
            ->mapWithKeys(fn (War $row): array => [(int) $row->getAttribute('alliance_id') => [
                'received' => (int) $row->getAttribute('received'),
                'countered' => (int) $row->getAttribute('countered'),
            ]]);

        $allianceIds = $stats->keys()
            ->merge(RaidAllianceProfile::query()->whereNotNull('bank_evidence_at')->pluck('alliance_id'))
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();

        if ($allianceIds->isEmpty()) {
            return 0;
        }

        $scores = Alliance::query()->whereIn('id', $allianceIds->all())->pluck('score', 'id');
        $rows = $allianceIds->map(function (int $allianceId) use ($stats, $scores, $priorCountered, $priorUncountered, $now): array {
            $received = (int) ($stats[$allianceId]['received'] ?? 0);
            $countered = (int) ($stats[$allianceId]['countered'] ?? 0);

            return [
                'alliance_id' => $allianceId,
                'alliance_score' => round((float) ($scores[$allianceId] ?? 0.0), 2),
                'raids_received_30d' => $received,
                'countered_30d' => $countered,
                'counter_rate' => round(($countered + $priorCountered) / ($received + $priorCountered + $priorUncountered), 4),
                'computed_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        })->all();

        RaidAllianceProfile::query()->upsert(
            $rows,
            ['alliance_id'],
            ['alliance_score', 'raids_received_30d', 'countered_30d', 'counter_rate', 'computed_at', 'updated_at'],
        );

        return count($rows);
    }
}
