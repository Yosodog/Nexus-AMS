<?php

namespace App\Services\StaffWorkQueue;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use InvalidArgumentException;

final readonly class StaffWorkItem
{
    public CarbonImmutable $createdAt;

    public ?CarbonImmutable $dueAt;

    /**
     * @param  list<string>  $searchTerms
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
    }

    public function key(): string
    {
        return $this->type.':'.$this->id;
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
        return [
            'key' => $this->key(),
            'type' => $this->type,
            'id' => $this->id,
            'type_label' => $this->typeLabel,
            'subject' => $this->subject,
            'created_at' => $this->createdAt->toIso8601String(),
            'due_at' => $this->dueAt?->toIso8601String(),
            'urgency_hint' => $this->urgencyHint,
            'owner_key' => $this->ownerKey,
            'owner_label' => $this->ownerLabel,
            'status_label' => $this->statusLabel,
            'status_intent' => $this->statusIntent,
            'status_icon' => $this->statusIcon,
            'next_action_label' => $this->nextActionLabel,
            'url' => $this->url,
            'search_terms' => array_values(array_map('strval', $this->searchTerms)),
        ];
    }
}
