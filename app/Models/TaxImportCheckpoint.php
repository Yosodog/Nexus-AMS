<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class TaxImportCheckpoint extends Model
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
            'last_attempted_at' => 'datetime',
            'last_succeeded_at' => 'datetime',
            'last_failed_at' => 'datetime',
            'last_imported_at' => 'datetime',
        ];
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

    public function latestAttemptFailed(): bool
    {
        return $this->last_failed_at !== null && $this->last_error !== null;
    }
}
