<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class DepositImportCheckpoint extends Model
{
    protected $fillable = [
        'alliance_id',
        'last_scanned_id',
        'last_attempted_at',
        'last_succeeded_at',
        'last_failed_at',
        'last_imported_at',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'alliance_id' => 'integer',
            'last_scanned_id' => 'integer',
            'last_attempted_at' => 'datetime',
            'last_succeeded_at' => 'datetime',
            'last_failed_at' => 'datetime',
            'last_imported_at' => 'datetime',
        ];
    }

    public static function lastScannedId(int $allianceId): int
    {
        return (int) self::query()->firstOrCreate(
            ['alliance_id' => $allianceId],
            ['last_scanned_id' => 0],
        )->last_scanned_id;
    }

    public static function advance(int $allianceId, int $recordId): void
    {
        self::query()->firstOrCreate(
            ['alliance_id' => $allianceId],
            ['last_scanned_id' => 0],
        );

        self::query()
            ->where('alliance_id', $allianceId)
            ->where('last_scanned_id', '<', $recordId)
            ->update(['last_scanned_id' => $recordId]);
    }

    public static function recordAttempt(int $allianceId): void
    {
        self::query()->updateOrCreate(
            ['alliance_id' => $allianceId],
            ['last_attempted_at' => now()],
        );
    }

    public static function recordSuccess(int $allianceId): void
    {
        self::query()->updateOrCreate(
            ['alliance_id' => $allianceId],
            [
                'last_succeeded_at' => now(),
                'last_error' => null,
            ],
        );
    }

    public static function recordFailure(int $allianceId, string $error): void
    {
        self::query()->updateOrCreate(
            ['alliance_id' => $allianceId],
            [
                'last_attempted_at' => now(),
                'last_failed_at' => now(),
                'last_error' => Str::limit(Str::squish($error), 1000, ''),
            ],
        );
    }

    public static function recordImport(int $allianceId): void
    {
        self::query()->updateOrCreate(
            ['alliance_id' => $allianceId],
            ['last_imported_at' => now()],
        );
    }
}
