<?php

namespace App\Services;

use App\DataTransferObjects\AllianceFinanceData;
use App\Events\AllianceIncomeOccurred;
use App\Exceptions\PWQueryFailedException;
use App\Models\AllianceFinanceEntry;
use App\Models\Taxes;
use App\Models\TaxImportCheckpoint;
use App\Models\TaxImportRejection;
use Carbon\Exceptions\InvalidFormatException;
use Exception;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
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

        $lastTaxId = self::getLastScannedTaxRecordId($alliance_id);
        $bankRecords = self::getAllianceTaxRecords($alliance_id, $lastTaxId + 1, $client);
        $newLastId = $lastTaxId;

        $ddService = app(DirectDepositService::class);
        $updatedDates = [];

        foreach ($bankRecords->sortBy(fn (object $record): int => $record->id)->values() as $record) {
            if ($record->id <= $newLastId) {
                continue;
            }

            if ($record->receiver_id !== $alliance_id || $record->receiver_type !== 2) {
                Log::error('Tax import received a bank record outside the requested alliance.', [
                    'alliance_id' => $alliance_id,
                    'tax_id' => $record->id,
                    'receiver_id' => $record->receiver_id,
                    'receiver_type' => $record->receiver_type,
                ]);

                break;
            }

            if ($record->tax_id <= 0) {
                Log::error('Tax import received a record without a tax bracket ID.', [
                    'alliance_id' => $alliance_id,
                    'record_id' => $record->id,
                ]);

                break;
            }

            $existingTax = Taxes::query()->find($record->id);
            if ($existingTax) {
                if ((int) $existingTax->receiver_id !== $alliance_id) {
                    Log::error('Tax import record ID conflicts with another alliance.', [
                        'alliance_id' => $alliance_id,
                        'tax_id' => $record->id,
                        'existing_receiver_id' => $existingTax->receiver_id,
                    ]);

                    break;
                }

                self::advanceCheckpoint($alliance_id, $record->id);
                $newLastId = $record->id;

                continue;
            }

            try {
                $recordedAt = self::parseApiTimestamp($record->date);
            } catch (InvalidFormatException $exception) {
                self::quarantineInvalidTimestamp($alliance_id, $record);

                Log::warning('Quarantined tax record with an invalid timestamp.', [
                    'alliance_id' => $alliance_id,
                    'tax_id' => $record->id,
                    'error' => $exception->getMessage(),
                ]);

                self::advanceCheckpoint($alliance_id, $record->id);
                $newLastId = $record->id;

                continue;
            }

            try {
                // Process DD. If the tax_id matches the DD tax ID, then it will process the DD and return what is left for taxes.
                $record = $ddService->process($record);

                $taxModel = DB::transaction(function () use ($record, $recordedAt) {
                    return Taxes::create([
                        'id' => $record->id, // Use PW tax record ID as our primary key
                        'date' => $recordedAt,
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

                if ($taxModel) {
                    $dateKey = Carbon::parse($taxModel->date)->toDateString();
                    $updatedDates[$dateKey] = true;
                }

                self::advanceCheckpoint($alliance_id, $record->id);
                $newLastId = $record->id;
            } catch (Throwable $e) {
                Log::error('Failed to process tax record', [
                    'tax_id' => $record->id,
                    'error' => $e->getMessage(),
                ]);

                break;
            }
        }

        foreach (array_keys($updatedDates) as $date) {
            self::recordDailyTaxIncome($date);
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
        return self::getAllianceTaxRecords($alliance_id, 1, $client);
    }

    /**
     * @throws PWQueryFailedException
     * @throws ConnectionException
     */
    protected static function getAllianceTaxRecords(
        int $allianceId,
        int $minimumId,
        ?QueryService $client = null
    ): Collection {
        return collect(app(TaxRecordQueryService::class)->getAllianceTaxes(
            $allianceId,
            minimumId: max(1, $minimumId),
            client: $client,
        ));
    }

    public static function getLastScannedTaxRecordId(?int $allianceId = null): int
    {
        if ($allianceId === null) {
            return (int) (Taxes::query()->max('id') ?? 0);
        }

        $durableTaxRecordId = max(
            (int) (Taxes::query()
                ->where('receiver_id', $allianceId)
                ->max('id') ?? 0),
            (int) (TaxImportRejection::query()
                ->where('alliance_id', $allianceId)
                ->max('tax_record_id') ?? 0),
        );

        $checkpointId = TaxImportCheckpoint::query()
            ->where('alliance_id', $allianceId)
            ->value('last_scanned_id');

        if ($checkpointId === null) {
            self::setCheckpoint($allianceId, $durableTaxRecordId);

            return $durableTaxRecordId;
        }

        $checkpointId = (int) $checkpointId;

        if ($checkpointId > $durableTaxRecordId) {
            Log::warning('Rewinding tax import checkpoint to the last durable tax record.', [
                'alliance_id' => $allianceId,
                'checkpoint_id' => $checkpointId,
                'durable_tax_record_id' => $durableTaxRecordId,
            ]);

            self::setCheckpoint($allianceId, $durableTaxRecordId);

            return $durableTaxRecordId;
        }

        return $checkpointId;
    }

    protected static function setCheckpoint(int $allianceId, int $recordId): void
    {
        TaxImportCheckpoint::query()->updateOrCreate(
            ['alliance_id' => $allianceId],
            ['last_scanned_id' => $recordId],
        );
    }

    protected static function advanceCheckpoint(int $allianceId, int $recordId): void
    {
        TaxImportCheckpoint::query()->firstOrCreate(
            ['alliance_id' => $allianceId],
            ['last_scanned_id' => 0],
        );

        TaxImportCheckpoint::query()
            ->where('alliance_id', $allianceId)
            ->where('last_scanned_id', '<', $recordId)
            ->update(['last_scanned_id' => $recordId]);
    }

    protected static function parseApiTimestamp(string $timestamp): Carbon
    {
        return Carbon::parse($timestamp, 'UTC')->utc();
    }

    protected static function quarantineInvalidTimestamp(int $allianceId, object $record): void
    {
        TaxImportRejection::query()->updateOrCreate(
            [
                'alliance_id' => $allianceId,
                'tax_record_id' => $record->id,
            ],
            [
                'reason' => 'invalid_timestamp',
                'raw_timestamp' => $record->date,
                'payload' => get_object_vars($record),
            ],
        );
    }

    protected static function recordDailyTaxIncome(string $date): void
    {
        $resourceSelects = collect(PWHelperService::resources())->map(
            fn ($res) => "SUM(`{$res}`) as `{$res}`"
        )->implode(', ');

        $aggregate = Taxes::query()
            ->where('day', $date)
            ->selectRaw('COUNT(*) as records, '.$resourceSelects)
            ->first();

        if (! $aggregate) {
            return;
        }

        $sourceId = (int) str_replace('-', '', $date);

        $financeData = new AllianceFinanceData(
            direction: AllianceFinanceEntry::DIRECTION_INCOME,
            category: 'tax',
            description: "Alliance tax intake for {$date}",
            date: Carbon::parse($date),
            nationId: null,
            accountId: null,
            sourceType: Taxes::class,
            sourceId: $sourceId,
            money: (float) ($aggregate->money ?? 0.0),
            coal: (float) ($aggregate->coal ?? 0.0),
            oil: (float) ($aggregate->oil ?? 0.0),
            uranium: (float) ($aggregate->uranium ?? 0.0),
            iron: (float) ($aggregate->iron ?? 0.0),
            bauxite: (float) ($aggregate->bauxite ?? 0.0),
            lead: (float) ($aggregate->lead ?? 0.0),
            gasoline: (float) ($aggregate->gasoline ?? 0.0),
            munitions: (float) ($aggregate->munitions ?? 0.0),
            steel: (float) ($aggregate->steel ?? 0.0),
            aluminum: (float) ($aggregate->aluminum ?? 0.0),
            food: (float) ($aggregate->food ?? 0.0),
            meta: [
                '_merge_mode' => 'replace',
                'records' => (int) ($aggregate->records ?? 0),
            ],
        );

        event(new AllianceIncomeOccurred($financeData->toArray()));
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
                'total_money' => (float) ($sums->money ?? 0),
                'top_resource' => collect($resources)
                    ->mapWithKeys(fn ($res) => [$res => $sums->$res])
                    ->sortDesc()
                    ->keys()
                    ->first(),
                'transaction_count' => (int) $transactionCount,
                'average_daily_money' => (float) ($dailyAvg ?? 0),
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
                    'data' => $results->pluck($res)
                        ->map(fn ($value) => (float) $value)
                        ->toArray(),
                ];
            } else {
                $data[$res] = $results->map(fn ($row) => [
                    'day' => $row->day,
                    'total' => (float) ($row->$res ?? 0),
                ])->all();
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
