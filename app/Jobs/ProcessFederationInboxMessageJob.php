<?php

namespace App\Jobs;

use App\Domain\Federation\Enums\InboxStatus;
use App\Domain\Federation\Services\FederationInboxProcessor;
use App\Models\FederationInboxMessage;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessFederationInboxMessageJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 10;

    public int $timeout = 60;

    public function __construct(public readonly string $inboxMessageId) {}

    public function handle(FederationInboxProcessor $processor): void
    {
        $message = FederationInboxMessage::query()->find($this->inboxMessageId);

        if (! $message instanceof FederationInboxMessage) {
            return;
        }

        $processor->process($message);
    }

    public function uniqueId(): string
    {
        return $this->inboxMessageId;
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 60, 120, 300, 600];
    }

    public function failed(?\Throwable $exception): void
    {
        FederationInboxMessage::query()
            ->whereKey($this->inboxMessageId)
            ->whereIn('status', [InboxStatus::Accepted->value, InboxStatus::Processing->value])
            ->update([
                'status' => InboxStatus::Quarantined->value,
                'safe_error_code' => 'temporary_unavailable',
                'decrypted_payload' => null,
                'envelope_body' => null,
                'quarantined_at' => now(),
                'next_attempt_at' => null,
                'updated_at' => now(),
            ]);
    }
}
