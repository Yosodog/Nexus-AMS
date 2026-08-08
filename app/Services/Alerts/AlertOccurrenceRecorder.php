<?php

namespace App\Services\Alerts;

use App\Models\AlertOccurrence;
use App\Models\AlertSubscription;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class AlertOccurrenceRecorder
{
    public function __construct(
        private readonly AlertEventCatalog $catalog,
        private readonly AlertDeliveryService $deliveries,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function record(
        string $eventKey,
        string $sourceType,
        string|int $sourceId,
        string $dedupeKey,
        array $payload,
        CarbonInterface $occurredAt,
        ?CarbonInterface $observedAt = null,
        ?int $allianceId = null,
        ?int $audienceUserId = null,
        ?string $subjectType = null,
        string|int|null $subjectId = null,
        ?string $sourceVersion = null,
        ?string $correlationKey = null,
        ?string $deepLinkPath = null,
        bool $isTest = false,
        ?AlertSubscription $subscription = null,
        bool $discordEnabled = false,
    ): AlertOccurrence {
        $definition = $this->catalog->get($eventKey);

        try {
            return DB::transaction(function () use (
                $definition,
                $eventKey,
                $sourceType,
                $sourceId,
                $dedupeKey,
                $payload,
                $occurredAt,
                $observedAt,
                $allianceId,
                $audienceUserId,
                $subjectType,
                $subjectId,
                $sourceVersion,
                $correlationKey,
                $deepLinkPath,
                $isTest,
                $subscription,
                $discordEnabled,
            ): AlertOccurrence {
                $occurrence = AlertOccurrence::query()->firstOrCreate(
                    ['dedupe_key' => $dedupeKey],
                    [
                        'event_key' => $eventKey,
                        'schema_version' => $definition->schemaVersion,
                        'alliance_id' => $allianceId,
                        'audience_user_id' => $audienceUserId,
                        'source_type' => $sourceType,
                        'source_id' => (string) $sourceId,
                        'source_version' => $sourceVersion,
                        'subject_type' => $subjectType,
                        'subject_id' => $subjectId === null ? null : (string) $subjectId,
                        'deep_link_path' => $deepLinkPath,
                        'severity' => $definition->severity,
                        'sensitivity' => $definition->sensitivity,
                        'payload' => $this->catalog->safePayload($eventKey, $payload),
                        'occurred_at' => $occurredAt,
                        'observed_at' => $observedAt,
                        'received_at' => now(),
                        'stale_at' => $definition->staleAfterMinutes === null
                            ? null
                            : $occurredAt->copy()->addMinutes($definition->staleAfterMinutes),
                        'correlation_key' => $correlationKey,
                        'is_test' => $isTest,
                    ],
                );

                if ($occurrence->wasRecentlyCreated) {
                    $this->deliveries->createForOccurrence(
                        $occurrence,
                        $subscription,
                        $discordEnabled,
                    );
                }

                return $occurrence;
            }, attempts: 5);
        } catch (QueryException $exception) {
            if ((string) ($exception->errorInfo[0] ?? '') !== '23000') {
                throw $exception;
            }

            return AlertOccurrence::query()->where('dedupe_key', $dedupeKey)->firstOrFail();
        }
    }
}
