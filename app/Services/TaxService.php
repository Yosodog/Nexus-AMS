<?php

namespace App\Services;

use App\GraphQL\Models\BankRecords;
use App\Models\Taxes;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TaxService
{
    /**
     * @param int $alliance_id
     * @return BankRecords
     */
    public static function getAllianceTaxes(int $alliance_id): BankRecords
    {
        $alliance = AllianceQueryService::getAllianceWithTaxes($alliance_id);

        return $alliance->taxrecs;
    }

    /**
     * @return int
     */
    public static function getLastScannedTaxRecordId(): int
    {
        return Taxes::max('id') ?? 0;
    }

    /**
     * @param int $alliance_id
     * @return int The last scanned ID is returned
     */
    public static function updateAllianceTaxes(int $alliance_id): int
    {
        $taxes = TaxService::getAllianceTaxes($alliance_id);
        $lastTaxId = TaxService::getLastScannedTaxRecordId();
        $newLastId = $lastTaxId;

        foreach ($taxes as $record) {
            if ($record->id <= $lastTaxId) {
                continue;
            }

            try {
                DB::transaction(function () use ($record) {
                    Taxes::create([
                        'id' => $record->id, // Use PW tax record ID as our primary key
                        'date' => Carbon::parse($record->date)->toDateTimeString(),
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
            } catch (\Throwable $e) {
                Log::error('Failed to insert tax record', [
                    'tax_id' => $record->id,
                    'error' => $e->getMessage()
                ]);
            }
        }

        return $newLastId;

    }
}