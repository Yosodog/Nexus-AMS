<?php

namespace App\Jobs;

use App\Domain\Federation\Enums\InboxStatus;
use App\Domain\Federation\Enums\OutboxStatus;
use App\Models\FederationCoalitionInvitation;
use App\Models\FederationCoalitionProposal;
use App\Models\FederationInboxMessage;
use App\Models\FederationLink;
use App\Models\FederationLinkInvitation;
use App\Models\FederationOutboxMessage;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class PruneFederationMessagesJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 120;

    public function handle(): void
    {
        $bodyCutoff = now()->subDays(max((int) config('federation.processed_body_retention_days', 30), 1));
        $tombstoneCutoff = now()->subDays(max((int) config('federation.tombstone_retention_days', 180), 1));

        FederationInboxMessage::query()
            ->whereIn('status', [InboxStatus::Processed->value, InboxStatus::Quarantined->value])
            ->where('created_at', '<=', $bodyCutoff)
            ->update([
                'envelope_body' => null,
                'decrypted_payload' => null,
                'updated_at' => now(),
            ]);
        FederationOutboxMessage::query()
            ->whereIn('status', [
                OutboxStatus::Validated->value,
                OutboxStatus::Failed->value,
                OutboxStatus::Expired->value,
            ])
            ->where('created_at', '<=', $bodyCutoff)
            ->update([
                'envelope_body' => null,
                'updated_at' => now(),
            ]);
        FederationInboxMessage::query()
            ->where('created_at', '<=', $tombstoneCutoff)
            ->whereNotNull('processed_at')
            ->delete();
        FederationOutboxMessage::query()
            ->where('created_at', '<=', $tombstoneCutoff)
            ->whereIn('status', [
                OutboxStatus::Validated->value,
                OutboxStatus::Failed->value,
                OutboxStatus::Expired->value,
            ])
            ->delete();

        foreach ([
            FederationLinkInvitation::class,
            FederationCoalitionInvitation::class,
            FederationCoalitionProposal::class,
        ] as $workflowClass) {
            $workflowClass::query()
                ->where('updated_at', '<=', $tombstoneCutoff)
                ->whereIn('status', ['rejected', 'cancelled', 'completed', 'expired'])
                ->delete();
        }

        FederationLink::query()
            ->where('status', 'expired')
            ->whereNull('active_at')
            ->where('updated_at', '<=', $tombstoneCutoff)
            ->whereNotExists(fn ($query) => $query
                ->selectRaw('1')
                ->from('federation_outbox_messages')
                ->whereColumn('federation_outbox_messages.federation_link_id', 'federation_links.id'))
            ->delete();
    }
}
