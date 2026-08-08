<?php

namespace App\Jobs;

use App\Domain\Federation\Enums\OutboxStatus;
use App\Models\FederationOutboxMessage;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SweepFederationOutboxJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 60;

    public function handle(): void
    {
        if (! (bool) config('federation.enabled', false)) {
            return;
        }

        FederationOutboxMessage::query()
            ->where('status', OutboxStatus::Pending->value)
            ->where('expires_at', '>', now())
            ->where(function ($query): void {
                $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now());
            })
            ->oldest('next_attempt_at')
            ->limit(200)
            ->pluck('id')
            ->each(fn (string $id) => DeliverFederationEnvelopeJob::dispatch($id));

        FederationOutboxMessage::query()
            ->whereIn('status', [OutboxStatus::Pending->value, OutboxStatus::Delivering->value])
            ->where('expires_at', '<=', now())
            ->update([
                'status' => OutboxStatus::Expired->value,
                'safe_error_code' => 'message_expired',
                'failed_at' => now(),
                'envelope_body' => null,
                'updated_at' => now(),
            ]);
    }
}
