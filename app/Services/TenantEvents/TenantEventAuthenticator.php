<?php

declare(strict_types=1);

namespace App\Services\TenantEvents;

use App\DataTransferObjects\TenantEvents\TenantEvent;
use App\Enums\TenantEventRejectionReason;
use App\Enums\TenantEventType;
use App\Exceptions\TenantEventConfigurationException;
use App\Exceptions\TenantEventRejectedException;
use App\Services\RuntimeBuildMetadata;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use JsonException;
use Throwable;

final readonly class TenantEventAuthenticator
{
    public const CONTRACT_VERSION = 1;

    public const PURPOSE = 'tenant-event';

    /** @var list<string> */
    private const TRANSPORT_FIELDS = [
        'body',
        'contract_version',
        'tenant_id',
        'purpose',
        'timestamp',
        'nonce',
        'body_sha256',
        'signature',
    ];

    /** @var list<string> */
    private const BODY_FIELDS = [
        'delivery_id',
        'event_id',
        'contract_version',
        'tenant_id',
        'type',
        'subject',
        'reason',
        'occurred_at',
        'trace_id',
    ];

    public function __construct(
        private TenantEventKey $key,
        private RuntimeBuildMetadata $build,
    ) {}

    public function assertConfigured(): void
    {
        $this->tenantId();
        $this->key->value();
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    public function verify(array $fields, ?CarbonImmutable $now = null): TenantEvent
    {
        $this->assertExactKeys($fields, self::TRANSPORT_FIELDS, TenantEventRejectionReason::MalformedEnvelope);

        foreach (self::TRANSPORT_FIELDS as $field) {
            if (! is_string($fields[$field])) {
                $this->reject(TenantEventRejectionReason::MalformedEnvelope);
            }
        }

        /** @var string $body */
        $body = $fields['body'];
        /** @var string $contract */
        $contract = $fields['contract_version'];
        /** @var string $tenantId */
        $tenantId = $fields['tenant_id'];
        /** @var string $purpose */
        $purpose = $fields['purpose'];
        /** @var string $timestamp */
        $timestamp = $fields['timestamp'];
        /** @var string $nonce */
        $nonce = $fields['nonce'];
        /** @var string $bodyDigest */
        $bodyDigest = $fields['body_sha256'];
        /** @var string $signature */
        $signature = $fields['signature'];

        if (strlen($body) > $this->boundedConfig('max_body_bytes', 1_024, 65_536, 8_192)
            || strlen($contract) > 3
            || strlen($tenantId) > 36
            || strlen($purpose) > 64
            || preg_match('/\A[0-9]{10}\z/D', $timestamp) !== 1
            || preg_match('/\A[a-f0-9]{32}\z/D', $nonce) !== 1
            || preg_match('/\A[a-f0-9]{64}\z/D', $bodyDigest) !== 1
            || preg_match('/\A[a-f0-9]{64}\z/D', $signature) !== 1
            || ! hash_equals(hash('sha256', $body), $bodyDigest)) {
            $this->reject(TenantEventRejectionReason::AuthenticationFailed);
        }

        if ($contract !== (string) self::CONTRACT_VERSION || $purpose !== self::PURPOSE) {
            $this->reject(TenantEventRejectionReason::UnsupportedContract);
        }

        $expectedSignature = hash_hmac(
            'sha256',
            self::canonicalPayload($tenantId, $timestamp, $nonce, $bodyDigest),
            $this->key->value(),
        );

        if (! hash_equals($expectedSignature, $signature)) {
            $this->reject(TenantEventRejectionReason::AuthenticationFailed);
        }

        if (! hash_equals($this->tenantId(), $tenantId)) {
            $this->reject(TenantEventRejectionReason::WrongTenant);
        }

        $publishedAt = CarbonImmutable::createFromTimestampUTC((int) $timestamp);
        $currentTimestamp = ($now ?? CarbonImmutable::now())->getTimestamp();
        $maxAge = $this->boundedConfig('max_age_seconds', 30, 900, 300);
        $futureTolerance = $this->boundedConfig('future_tolerance_seconds', 0, 60, 30);

        if ($publishedAt->getTimestamp() < $currentTimestamp - $maxAge
            || $publishedAt->getTimestamp() > $currentTimestamp + $futureTolerance) {
            $this->reject(TenantEventRejectionReason::ExpiredEnvelope);
        }

        try {
            $event = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->reject(TenantEventRejectionReason::InvalidEventBody);
        }

        if (! is_array($event) || array_is_list($event)) {
            $this->reject(TenantEventRejectionReason::InvalidEventBody);
        }

        $this->assertExactKeys($event, self::BODY_FIELDS, TenantEventRejectionReason::InvalidEventBody);

        if (($event['contract_version'] ?? null) !== self::CONTRACT_VERSION
            || ($event['tenant_id'] ?? null) !== $tenantId) {
            $this->reject(TenantEventRejectionReason::InvalidEventBody);
        }

        $deliveryId = $event['delivery_id'] ?? null;
        $eventId = $event['event_id'] ?? null;
        $eventType = is_string($event['type'] ?? null)
            ? TenantEventType::tryFrom($event['type'])
            : null;
        $traceId = $event['trace_id'] ?? null;

        if (! is_string($deliveryId) || ! Str::isUlid($deliveryId)
            || ! is_string($eventId)
            || preg_match('/\A[a-z0-9][a-z0-9:._-]{0,190}\z/D', $eventId) !== 1
            || ! is_string($traceId)
            || (! Str::isUlid($traceId) && ! Str::isUuid($traceId))) {
            $this->reject(TenantEventRejectionReason::InvalidEventBody);
        }

        if ($eventType === null) {
            $this->reject(TenantEventRejectionReason::UnsupportedEventType);
        }

        $subjectId = $this->subjectId($eventType, $event['subject'] ?? null);
        $matchedAllianceIds = $this->matchedAllianceIds($event['reason'] ?? null);
        $occurredAt = $this->occurredAt($event['occurred_at'] ?? null);

        if ($occurredAt->getTimestamp() > $publishedAt->getTimestamp() + $futureTolerance) {
            $this->reject(TenantEventRejectionReason::FutureEvent);
        }

        return new TenantEvent(
            deliveryId: strtoupper($deliveryId),
            eventId: $eventId,
            contractVersion: self::CONTRACT_VERSION,
            tenantId: $tenantId,
            type: $eventType,
            subjectId: $subjectId,
            matchedAllianceIds: $matchedAllianceIds,
            occurredAt: $occurredAt,
            traceId: Str::isUlid($traceId) ? strtoupper($traceId) : strtolower($traceId),
            bodyDigest: $bodyDigest,
            transportNonce: $nonce,
            publishedAt: $publishedAt,
        );
    }

    public static function canonicalPayload(
        string $tenantId,
        string $timestamp,
        string $nonce,
        string $bodyDigest,
    ): string {
        $fields = [
            'contract_version' => (string) self::CONTRACT_VERSION,
            'purpose' => self::PURPOSE,
            'tenant_id' => $tenantId,
            'timestamp' => $timestamp,
            'nonce' => $nonce,
            'body_sha256' => $bodyDigest,
        ];

        return implode("\n", array_map(
            static fn (string $field, string $value): string => $field.':'.strlen($value).':'.$value,
            array_keys($fields),
            array_values($fields),
        ));
    }

    private function tenantId(): string
    {
        $tenantId = $this->build->tenantId();

        if ($tenantId === null) {
            throw new TenantEventConfigurationException;
        }

        return $tenantId;
    }

    private function subjectId(TenantEventType $type, mixed $subject): int
    {
        if (! is_array($subject) || array_is_list($subject)) {
            $this->reject(TenantEventRejectionReason::InvalidEventBody);
        }

        return match ($type) {
            TenantEventType::WarDeclared => $this->positiveIntegerField($subject, 'war_id'),
        };
    }

    /** @return list<int> */
    private function matchedAllianceIds(mixed $reason): array
    {
        if (! is_array($reason) || array_is_list($reason)) {
            $this->reject(TenantEventRejectionReason::InvalidEventBody);
        }

        $this->assertExactKeys(
            $reason,
            ['matched_alliance_ids'],
            TenantEventRejectionReason::InvalidEventBody,
        );
        $allianceIds = $reason['matched_alliance_ids'];

        if (! is_array($allianceIds)
            || ! array_is_list($allianceIds)
            || $allianceIds === []
            || count($allianceIds) > 32) {
            $this->reject(TenantEventRejectionReason::InvalidEventBody);
        }

        foreach ($allianceIds as $allianceId) {
            if (! is_int($allianceId) || $allianceId < 1 || $allianceId > 2_147_483_647) {
                $this->reject(TenantEventRejectionReason::InvalidEventBody);
            }
        }

        if (count(array_unique($allianceIds, SORT_REGULAR)) !== count($allianceIds)) {
            $this->reject(TenantEventRejectionReason::InvalidEventBody);
        }

        sort($allianceIds, SORT_NUMERIC);

        return $allianceIds;
    }

    private function occurredAt(mixed $value): CarbonImmutable
    {
        if (! is_string($value)
            || strlen($value) > 40
            || preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(?:\.[0-9]{1,6})?(?:Z|[+-][0-9]{2}:[0-9]{2})\z/D', $value) !== 1) {
            $this->reject(TenantEventRejectionReason::InvalidEventBody);
        }

        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (Throwable) {
            $this->reject(TenantEventRejectionReason::InvalidEventBody);
        }
    }

    /** @param array<string, mixed> $values */
    private function positiveIntegerField(array $values, string $field): int
    {
        $this->assertExactKeys($values, [$field], TenantEventRejectionReason::InvalidEventBody);
        $value = $values[$field];

        if (! is_int($value) || $value < 1) {
            $this->reject(TenantEventRejectionReason::InvalidEventBody);
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  list<string>  $expected
     */
    private function assertExactKeys(
        array $values,
        array $expected,
        TenantEventRejectionReason $reason,
    ): void {
        $actual = array_keys($values);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);

        if ($actual !== $expected) {
            $this->reject($reason);
        }
    }

    private function boundedConfig(string $key, int $minimum, int $maximum, int $default): int
    {
        $value = config('nexus.tenant_events.'.$key);

        return is_int($value) && $value >= $minimum && $value <= $maximum
            ? $value
            : $default;
    }

    private function reject(TenantEventRejectionReason $reason): never
    {
        throw new TenantEventRejectedException($reason);
    }
}
