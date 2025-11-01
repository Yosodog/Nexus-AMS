<?php

namespace App\Services;

use App\Exceptions\PWQueryFailedException;
use App\Models\Taxes;
use Exception;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class TaxService
{
    /**
     * @return int The last scanned ID is returned
     *
     * @throws ConnectionException
     * @throws PWQueryFailedException
     */
    public static function updateAllianceTaxes(int $alliance_id, ?QueryService $client = null): int
    {
        Cache::forget('tax_summary_stats');
        Cache::forget('tax_resource_chart_data');
        Cache::forget('tax_daily_totals');

        $taxes = self::getAllianceTaxes($alliance_id, $client);
        $lastTaxId = self::getLastScannedTaxRecordId($alliance_id);
        $newLastId = $lastTaxId;

        $ddService = app(DirectDepositService::class);

        foreach ($taxes as $record) {
            if ($record->id <= $lastTaxId) {
                continue;
            }

            // Process DD. If the tax_id matches the DD tax ID, then it will process the DD and return what is left for taxes.
            $record = $ddService->process($record);

            try {
                DB::transaction(function () use ($record) {
                    Taxes::create([
                        'id' => $record->id, // Use PW tax record ID as our primary key
                        'date' => $record->date,
                        'sender_id' => $record->sender_id,
                        'receiver_id' => $record->receiver_id,
                        'receiver_type' => $record->receiver_type,

                        'money' => $record->money ?? 0,
                        'coal' => $record->coal ?? 0,
                        'oil' => $record->oil ?? 0,
                        'uranium' => $record->uranium ?? 0,
                        'iron' => $record->iron ?? 0,
                        'bauxite' => $record->bauxite ?? 0,
                        'lead' => $record->lead ?? 0,
                        'gasoline' => $record->gasoline ?? 0,
                        'munitions' => $record->munitions ?? 0,
                        'steel' => $record->steel ?? 0,
                        'aluminum' => $record->aluminum ?? 0,
                        'food' => $record->food ?? 0,

                        'tax_id' => $record->tax_id, // Tax bracket ID
                    ]);
                });

                $newLastId = max($newLastId, $record->id);
            } catch (Throwable $e) {
                Log::error('Failed to insert tax record', [
                    'tax_id' => $record->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Pre-warm cache so users don't wait on page load
        self::getSummaryStats();
        self::getResourceChartData();
        self::getDailyTotals();

        return $newLastId;
    }

    /**
     * @throws PWQueryFailedException
     * @throws ConnectionException
     */
    public static function getAllianceTaxes(int $alliance_id, ?QueryService $client = null): Collection
    {
        return collect(AllianceQueryService::getAllianceWithTaxes($alliance_id, $client)->taxrecs);
    }

    public static function getLastScannedTaxRecordId(?int $allianceId = null): int
    {
        $query = Taxes::query();

        if ($allianceId !== null) {
            $query->where('receiver_id', $allianceId);
        }

        return (int) ($query->max('id') ?? 0);
    }

    /**
     * @throws Exception
     */
    public static function getSummaryStats(): array
    {
        $start = now()->subDays(30);
        $resources = PWHelperService::resources(false);
        $baseQuery = Taxes::where('date', '>=', $start);

        return Cache::remember('tax_summary_stats', now()->addMinutes(60), function () use ($resources, $baseQuery) {
            $sums = (clone $baseQuery)->selectRaw(
                collect($resources)->prepend('money')->map(fn ($r) => "SUM(`$r`) as `$r`")->implode(', ')
            )->first();

            $transactionCount = (clone $baseQuery)->count();
            $dailyAvg = (clone $baseQuery)
                ->select('day as d', DB::raw('SUM(money) as total'))
                ->groupBy('d')
                ->get()
                ->avg('total');

            return [
                'total_money' => $sums->money,
                'top_resource' => collect($resources)
                    ->mapWithKeys(fn ($res) => [$res => $sums->$res])
                    ->sortDesc()
                    ->keys()
                    ->first(),
                'transaction_count' => $transactionCount,
                'average_daily_money' => $dailyAvg,
            ];
        });
    }

    /**
     * @throws Exception
     */
    public static function getResourceChartData(): array
    {
        return Cache::remember('tax_resource_chart_data', now()->addMinutes(60), function () {
            return self::getAggregatedResourceData(true);
        });
    }

    private static function getAggregatedResourceData(bool $formatForChart): array
    {
        $start = now()->subDays(30);
        $resources = PWHelperService::resources();

        $results = Taxes::where('date', '>=', $start)
            ->select('day')
            ->addSelect(collect($resources)->map(fn ($r) => DB::raw("SUM(`$r`) as `$r`"))->toArray())
            ->groupBy('day')
            ->orderBy('day')
            ->get();

        $data = [];

        foreach ($resources as $res) {
            if ($formatForChart) {
                $data[$res] = [
                    'labels' => $results->pluck('day')->toArray(),
                    'data' => $results->pluck($res)->toArray(),
                ];
            } else {
                $data[$res] = $results->map(fn ($row) => [
                    'day' => $row->day,
                    'total' => $row->$res,
                ]);
            }
        }

        return $data;
    }

    /**
     * @throws Exception
     */
    public static function getDailyTotals(): array
    {
        return Cache::remember('tax_daily_totals', now()->addMinutes(60), function () {
            return self::getAggregatedResourceData(false);
        });
    }
}
