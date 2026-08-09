<?php

declare(strict_types=1);

namespace App\Services\TenantEvents;

use App\DataTransferObjects\TenantEvents\TenantEvent;
use App\Enums\TenantEventProcessingResult;
use App\Enums\TenantEventRejectionReason;
use App\Enums\TenantEventType;
use App\Exceptions\TenantEventConflictException;
use App\Exceptions\TenantEventRejectedException;
use App\Exceptions\TenantEventRetryableException;
use App\Models\TenantEventReceipt;
use App\Models\War;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class TenantEventProcessor
{
    public function __construct(
        private readonly WarDeclarationReactionService $warDeclarations,
    ) {}

    public function process(TenantEvent $event): TenantEventProcessingResult
    {
        return DB::transaction(function () use ($event): TenantEventProcessingResult {
            $existing = $this->findExistingReceipt($event);

            if ($existing !== null) {
                $this->assertMatchingReceipt($existing, $event);

                return TenantEventProcessingResult::Duplicate;
            }

            try {
                $receipt = TenantEventReceipt::query()->createOrFirst(
                    ['event_id' => $event->eventId],
                    [
                        'delivery_id' => $event->deliveryId,
                        'contract_version' => $event->contractVersion,
                        'event_type' => $event->type,
                        'subject_key' => $event->subjectKey(),
                        'event_digest' => $event->bodyDigest,
                        'transport_nonce' => $event->transportNonce,
                        'trace_id' => $event->traceId,
                        'occurred_at' => $event->occurredAt,
                        'published_at' => $event->publishedAt,
                        'processed_at' => now(),
                    ],
                );
            } catch (UniqueConstraintViolationException) {
                throw new TenantEventConflictException;
            }

            if (! $receipt->wasRecentlyCreated) {
                $this->assertMatchingReceipt($receipt, $event);

                return TenantEventProcessingResult::Duplicate;
            }

            $this->apply($event);

            return TenantEventProcessingResult::Processed;
        }, attempts: 3);
    }

    private function apply(TenantEvent $event): void
    {
        match ($event->type) {
            TenantEventType::WarDeclared => $this->applyWarDeclared($event),
        };
    }

    private function applyWarDeclared(TenantEvent $event): void
    {
        $war = War::query()
            ->select([
                'id',
                'att_id',
                'att_alliance_id',
                'att_alliance_position',
                'def_id',
                'def_alliance_id',
                'def_alliance_position',
            ])
            ->find($event->subjectId);

        if ($war === null) {
            throw new TenantEventRetryableException;
        }

        $warAllianceIds = array_values(array_filter([
            $this->nullablePositiveInteger($war->getAttribute('att_alliance_id')),
            $this->nullablePositiveInteger($war->getAttribute('def_alliance_id')),
        ]));

        foreach ($event->matchedAllianceIds as $matchedAllianceId) {
            if (! in_array($matchedAllianceId, $warAllianceIds, true)) {
                throw new TenantEventRejectedException(TenantEventRejectionReason::RoutingMismatch);
            }
        }

        $this->warDeclarations->react($war);
    }

    private function nullablePositiveInteger(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        $integer = (int) $value;

        return $integer > 0 ? $integer : null;
    }

    private function findExistingReceipt(TenantEvent $event): ?TenantEventReceipt
    {
        return TenantEventReceipt::query()
            ->where('event_id', $event->eventId)
            ->orWhere('delivery_id', $event->deliveryId)
            ->lockForUpdate()
            ->first();
    }

    private function assertMatchingReceipt(TenantEventReceipt $receipt, TenantEvent $event): void
    {
        if ($receipt->delivery_id !== $event->deliveryId
            || $receipt->event_id !== $event->eventId
            || $receipt->contract_version !== $event->contractVersion
            || $receipt->event_type !== $event->type
            || $receipt->subject_key !== $event->subjectKey()
            || ! hash_equals($receipt->event_digest, $event->bodyDigest)
            || $receipt->trace_id !== $event->traceId) {
            throw new TenantEventConflictException;
        }
    }
}
