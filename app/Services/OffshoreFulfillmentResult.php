<?php

namespace App\Services;

class OffshoreFulfillmentResult
{
    public const STATUS_SKIPPED = 'skipped';

    public const STATUS_FULFILLED = 'fulfilled';

    public const STATUS_FAILED = 'failed';

    public const STATUS_TIMEOUT = 'timeout';

    public function __construct(
        public readonly string $status,
        public readonly string $message,
        public readonly array $transfers = [],
        public readonly array $errors = [],
        public readonly array $guardrailBlocks = [],
        public readonly array $remainingDeficits = [],
        public readonly array $initialDeficits = []
    ) {}

    public function shouldSendWithdrawal(): bool
    {
        return in_array($this->status, [self::STATUS_SKIPPED, self::STATUS_FULFILLED], true);
    }

    public function requiresAdminReview(): bool
    {
        return in_array($this->status, [self::STATUS_FAILED, self::STATUS_TIMEOUT], true);
    }

    /**
     * @return array<int|string, mixed>
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'message' => $this->message,
            'transfers' => $this->transfers,
            'errors' => $this->errors,
            'guardrail_blocks' => $this->guardrailBlocks,
            'remaining_deficits' => $this->remainingDeficits,
            'initial_deficits' => $this->initialDeficits,
        ];
    }
}
