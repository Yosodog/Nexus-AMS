<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Throwable;

class SubscriptionRecordQuarantine
{
    public function quarantine(string $model, string $event, mixed $record, string $reason): void
    {
        $recordId = is_array($record) ? ($record['id'] ?? null) : null;

        try {
            $file = (string) config('subscriptions.ingestion.quarantine_file');
            File::ensureDirectoryExists(dirname($file));
            File::append($file, json_encode([
                'recorded_at' => now()->toIso8601String(),
                'model' => $model,
                'event' => $event,
                'reason' => $reason,
                'record' => $record,
            ], JSON_THROW_ON_ERROR).PHP_EOL);
        } catch (Throwable $exception) {
            Log::error('Failed to persist quarantined subscription record.', [
                'model' => $model,
                'event' => $event,
                'record_id' => $recordId,
                'reason' => $reason,
                'exception_class' => $exception::class,
                'error' => $exception->getMessage(),
            ]);
        }

        Log::warning('Quarantined subscription record.', [
            'model' => $model,
            'event' => $event,
            'record_id' => $recordId,
            'reason' => $reason,
        ]);
    }
}
