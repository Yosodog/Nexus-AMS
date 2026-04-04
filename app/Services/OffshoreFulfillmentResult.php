<?php

namespace App\Services;

readonly class OffshoreFulfillmentResult
{
    public const STATUS_SKIPPED = 'skipped';

    public const STATUS_FULFILLED = 'fulfilled';

    public const STATUS_FAILED = 'failed';

    public const STATUS_TIMEOUT = 'timeout';

    public function __construct(
        public string $status,
        public string $message,
        public array $transfers = [],
        public array $errors = [],
        public array $guardrailBlocks = [],
        public array $remainingDeficits = [],
        public array $initialDeficits = []
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
