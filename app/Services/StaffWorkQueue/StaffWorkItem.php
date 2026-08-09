<?php

namespace App\Services\StaffWorkQueue;

use App\Enums\OperationsAttentionReason;
use App\Enums\OperationsNextActor;
use App\Enums\OperationsPriority;
use App\Enums\OperationsSensitivity;
use App\Enums\OperationsSeverity;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use InvalidArgumentException;

final readonly class StaffWorkItem
{
    public CarbonImmutable $createdAt;

    public ?CarbonImmutable $dueAt;

    public CarbonImmutable $sourceUpdatedAt;

    public ?CarbonImmutable $operationalTargetAt;

    public ?CarbonImmutable $completedAt;

    public OperationsPriority $priority;

    public OperationsSeverity $severity;

    public OperationsNextActor $nextActor;

    public OperationsSensitivity $sensitivity;

    public StaffWorkQueueAction $nextAction;

    /**
     * @param  list<string>  $searchTerms
     * @param  list<string>  $interestedTeams
     * @param  list<OperationsAttentionReason|string>  $attentionReasons
     * @param  list<StaffWorkQueueContext>  $contexts
     * @param  array<string, int|float|string|bool|null>  $safeFacts
     * @param  list<string>  $capabilityKeys
     */
    public function __construct(
        public string $type,
        public int|string $id,
        public string $typeLabel,
        public string $subject,
        DateTimeInterface $createdAt,
        public ?string $ownerKey,
        public ?string $ownerLabel,
        public string $statusLabel,
        public string $statusIntent,
        public string $statusIcon,
        public string $nextActionLabel,
        public string $url,
        ?DateTimeInterface $dueAt = null,
        public ?string $urgencyHint = null,
        public array $searchTerms = [],
        public ?string $occurrenceKey = null,
        public ?string $summary = null,
        public ?string $domainStatusCode = null,
        public ?string $teamKey = null,
        public array $interestedTeams = [],
        public ?StaffWorkQueueActor $requester = null,
        public ?StaffWorkQueueActor $domainOwner = null,
        public ?StaffWorkQueueActor $waitingOn = null,
        ?OperationsNextActor $nextActor = null,
        ?OperationsPriority $priority = null,
        ?OperationsSeverity $severity = null,
        public bool $requiresStaffAction = true,
        public array $attentionReasons = [],
        public bool $blocked = false,
        public ?string $blockerSummary = null,
        ?DateTimeInterface $sourceUpdatedAt = null,
        ?DateTimeInterface $operationalTargetAt = null,
        ?DateTimeInterface $completedAt = null,
        public array $contexts = [],
        ?StaffWorkQueueAction $nextAction = null,
        public array $safeFacts = [],
        public array $capabilityKeys = [],
        ?OperationsSensitivity $sensitivity = null,
    ) {
        if (! preg_match('/\A[a-z][a-z0-9_]*\z/', $this->type)) {
            throw new InvalidArgumentException('Work queue item types must use stable snake-case identifiers.');
        }

        if (($this->ownerKey === null) !== ($this->ownerLabel === null)) {
            throw new InvalidArgumentException('Work queue owners require both a key and a readable label.');
        }

        if (! in_array($this->statusIntent, ['neutral', 'pending', 'active', 'success', 'warning', 'failure'], true)) {
            throw new InvalidArgumentException('Unsupported work queue status intent.');
        }

        if ($this->urgencyHint !== null && ! in_array($this->urgencyHint, StaffWorkQueueFilterSet::URGENCIES, true)) {
            throw new InvalidArgumentException('Unsupported work queue urgency hint.');
        }

        $this->createdAt = CarbonImmutable::instance($createdAt);
        $this->dueAt = $dueAt ? CarbonImmutable::instance($dueAt) : null;
        $this->sourceUpdatedAt = $sourceUpdatedAt
            ? CarbonImmutable::instance($sourceUpdatedAt)
            : $this->createdAt;
        $this->operationalTargetAt = $operationalTargetAt
            ? CarbonImmutable::instance($operationalTargetAt)
            : null;
        $this->completedAt = $completedAt ? CarbonImmutable::instance($completedAt) : null;
        $this->nextActor = $nextActor ?? OperationsNextActor::Staff;
        $this->priority = $priority ?? match ($this->urgencyHint) {
            'urgent' => OperationsPriority::P1,
            'attention' => OperationsPriority::P2,
            default => OperationsPriority::P3,
        };
        $this->severity = $severity ?? OperationsSeverity::Unknown;
        $this->sensitivity = $sensitivity ?? OperationsSensitivity::Restricted;
        $this->nextAction = $nextAction ?? new StaffWorkQueueAction(
            key: 'domain.view',
            label: $this->nextActionLabel,
            actor: $this->nextActor,
            url: $this->url,
        );

        foreach ($this->contexts as $context) {
            if (! $context instanceof StaffWorkQueueContext) {
                throw new InvalidArgumentException('Operations work-item contexts must use StaffWorkQueueContext values.');
            }
        }
    }

    public function key(): string
    {
        return $this->type.':'.$this->id;
    }

    public function resolvedOccurrenceKey(): string
    {
        return $this->occurrenceKey ?? hash(
            'sha256',
            $this->key().'|'.$this->createdAt->format('Y-m-d\\TH:i:s.uP'),
        );
    }

    public function sourceFingerprint(): string
    {
        return hash('sha256', json_encode([
            'work_key' => $this->key(),
            'occurrence_key' => $this->resolvedOccurrenceKey(),
            'domain_status' => $this->domainStatusCode ?? $this->statusLabel,
            'source_updated_at' => $this->sourceUpdatedAt->toIso8601String(),
            'due_at' => $this->dueAt?->toIso8601String(),
            'operational_target_at' => $this->operationalTargetAt?->toIso8601String(),
            'blocked' => $this->blocked,
            'safe_facts' => $this->safeFacts,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * Store cache-safe scalar data rather than serializing application objects.
     *
     * @return array{
     *     key: string,
     *     type: string,
     *     id: int|string,
     *     type_label: string,
     *     subject: string,
     *     created_at: string,
     *     due_at: string|null,
     *     urgency_hint: string|null,
     *     owner_key: string|null,
     *     owner_label: string|null,
     *     status_label: string,
     *     status_intent: string,
     *     status_icon: string,
     *     next_action_label: string,
     *     url: string,
     *     search_terms: list<string>
     * }
     */
    public function toArray(): array
    {
        $domainOwner = $this->domainOwner;

        if ($domainOwner === null && $this->ownerKey !== null && $this->ownerLabel !== null) {
            $domainOwner = new StaffWorkQueueActor('domain_owner', $this->ownerKey, $this->ownerLabel);
        }

        $attentionReasons = array_values(array_unique(array_map(
            static fn (OperationsAttentionReason|string $reason): string => $reason instanceof OperationsAttentionReason
                ? $reason->value
                : $reason,
            $this->attentionReasons,
        )));

        return [
            'schema_version' => 2,
            'key' => $this->key(),
            'work_key' => $this->key(),
            'occurrence_key' => $this->resolvedOccurrenceKey(),
            'source_fingerprint' => $this->sourceFingerprint(),
            'type' => $this->type,
            'source_type' => $this->type,
            'id' => $this->id,
            'domain_id' => $this->id,
            'type_label' => $this->typeLabel,
            'source_label' => $this->typeLabel,
            'subject' => $this->subject,
            'title' => $this->subject,
            'summary' => $this->summary,
            'created_at' => $this->createdAt->toIso8601String(),
            'entered_queue_at' => $this->createdAt->toIso8601String(),
            'due_at' => $this->dueAt?->toIso8601String(),
            'operational_target_at' => $this->operationalTargetAt?->toIso8601String(),
            'source_updated_at' => $this->sourceUpdatedAt->toIso8601String(),
            'completed_at' => $this->completedAt?->toIso8601String(),
            'urgency_hint' => $this->urgencyHint,
            'owner_key' => $this->ownerKey,
            'owner_label' => $this->ownerLabel,
            'requester' => $this->requester?->toArray(),
            'domain_owner' => $domainOwner?->toArray(),
            'waiting_on' => $this->waitingOn?->toArray(),
            'next_actor' => $this->nextActor->value,
            'status_label' => $this->statusLabel,
            'status_intent' => $this->statusIntent,
            'status_icon' => $this->statusIcon,
            'domain_status' => [
                'code' => $this->domainStatusCode ?? $this->statusLabel,
                'label' => $this->statusLabel,
                'intent' => $this->statusIntent,
                'icon' => $this->statusIcon,
            ],
            'team_key' => $this->teamKey,
            'interested_teams' => array_values(array_unique(array_map('strval', $this->interestedTeams))),
            'priority' => $this->priority->value,
            'severity' => $this->severity->value,
            'requires_staff_action' => $this->requiresStaffAction,
            'attention_reasons' => $attentionReasons,
            'blocked' => $this->blocked,
            'blocker_summary' => $this->blockerSummary,
            'next_action_label' => $this->nextActionLabel,
            'url' => $this->url,
            'next_action' => $this->nextAction->toArray(),
            'contexts' => array_map(
                static fn (StaffWorkQueueContext $context): array => $context->toArray(),
                $this->contexts,
            ),
            'safe_facts' => $this->safeFacts,
            'capability_keys' => array_values(array_unique(array_map('strval', $this->capabilityKeys))),
            'batch_policy' => 'coordination_only',
            'sensitivity' => $this->sensitivity->value,
            'search_terms' => array_values(array_map('strval', $this->searchTerms)),
        ];
    }
}
