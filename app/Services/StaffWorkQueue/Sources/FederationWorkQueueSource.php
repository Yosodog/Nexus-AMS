<?php

namespace App\Services\StaffWorkQueue\Sources;

use App\Domain\Federation\Enums\FederationWorkflowStatus;
use App\Domain\Federation\Enums\ImportState;
use App\Domain\Federation\Enums\ReceivedDisposition;
use App\Domain\Federation\Enums\ReceivedResourceState;
use App\Models\FederationCoalitionInvitation;
use App\Models\FederationCoalitionProposal;
use App\Models\FederationLinkInvitation;
use App\Models\FederationReceivedVersion;
use App\Models\MilcomOperation;
use App\Services\StaffWorkQueue\StaffWorkItem;
use App\Services\StaffWorkQueue\StaffWorkQueueSource;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

final class FederationWorkQueueSource implements StaffWorkQueueSource
{
    public const LINK_APPROVALS = 'federation_link_approvals';

    public const COALITION_WORKFLOWS = 'federation_coalition_workflows';

    public const RECEIVED_REVIEWS = 'federation_received_reviews';

    public const BLOCKED_IMPORTS = 'federation_blocked_imports';

    public const HELD_OPERATIONS = 'federation_held_operations';

    /** @var list<string> */
    private const CATEGORIES = [
        self::LINK_APPROVALS,
        self::COALITION_WORKFLOWS,
        self::RECEIVED_REVIEWS,
        self::BLOCKED_IMPORTS,
        self::HELD_OPERATIONS,
    ];

    public function __construct(private readonly string $category)
    {
        if (! in_array($this->category, self::CATEGORIES, true)) {
            throw new InvalidArgumentException('Unsupported federation work queue category.');
        }
    }

    public function type(): string
    {
        return $this->category;
    }

    public function label(): string
    {
        return match ($this->category) {
            self::LINK_APPROVALS => 'Federation link approvals',
            self::COALITION_WORKFLOWS => 'Federation coalition workflows',
            self::RECEIVED_REVIEWS => 'Received federation plans',
            self::BLOCKED_IMPORTS => 'Blocked federation imports',
            self::HELD_OPERATIONS => 'Held federation operations',
        };
    }

    public function ability(): string
    {
        return match ($this->category) {
            self::LINK_APPROVALS => 'manage-federation',
            self::COALITION_WORKFLOWS => 'manage-coalitions',
            self::RECEIVED_REVIEWS => 'review-federated-war-plans',
            self::BLOCKED_IMPORTS, self::HELD_OPERATIONS => 'import-federated-war-plans',
        };
    }

    /**
     * @return list<StaffWorkItem>
     */
    public function load(): array
    {
        if (! (bool) config('federation.enabled', false)) {
            return [];
        }

        return match ($this->category) {
            self::LINK_APPROVALS => $this->loadLinkApprovals(),
            self::COALITION_WORKFLOWS => $this->loadCoalitionWorkflows(),
            self::RECEIVED_REVIEWS => $this->loadReceivedReviews(),
            self::BLOCKED_IMPORTS => $this->loadBlockedImports(),
            self::HELD_OPERATIONS => $this->loadHeldOperations(),
        };
    }

    /**
     * @return list<StaffWorkItem>
     */
    private function loadLinkApprovals(): array
    {
        return FederationLinkInvitation::query()
            ->select([
                'id',
                'direction',
                'status',
                'expires_at',
                'created_at',
            ])
            ->whereIn('direction', ['inbound', 'outbound', 'endpoint_inbound', 'endpoint_outbound'])
            ->whereIn('status', [
                FederationWorkflowStatus::Pending->value,
                FederationWorkflowStatus::Approved->value,
            ])
            ->orderBy('created_at')
            ->get()
            ->map(function (FederationLinkInvitation $invitation): StaffWorkItem {
                $isEndpointChange = str_starts_with((string) $invitation->direction, 'endpoint_');
                $isLocalReview = in_array($invitation->direction, ['inbound', 'endpoint_inbound'], true);
                $isApproved = $invitation->status === FederationWorkflowStatus::Approved;

                return new StaffWorkItem(
                    type: $this->type(),
                    id: 'invitation:'.$invitation->id,
                    typeLabel: $isEndpointChange ? 'Federation endpoint change' : 'Federation link',
                    subject: $isEndpointChange
                        ? 'Federation endpoint change awaiting approval'
                        : 'Federation link workflow awaiting approval',
                    createdAt: $invitation->created_at,
                    ownerKey: null,
                    ownerLabel: null,
                    statusLabel: $isApproved
                        ? 'Peer approval received'
                        : ($isLocalReview ? 'Pending local review' : 'Awaiting peer approval'),
                    statusIntent: $isApproved || $isLocalReview ? 'pending' : 'neutral',
                    statusIcon: 'link',
                    nextActionLabel: $isLocalReview ? 'Review link approval' : 'Review link status',
                    url: $this->federationUrl('links'),
                    dueAt: $invitation->expires_at,
                    urgencyHint: $this->urgencyFor($invitation->expires_at),
                    searchTerms: [$invitation->id, (string) $invitation->direction],
                );
            })
            ->all();
    }

    /**
     * @return list<StaffWorkItem>
     */
    private function loadCoalitionWorkflows(): array
    {
        $invitations = FederationCoalitionInvitation::query()
            ->select([
                'id',
                'direction',
                'status',
                'expires_at',
                'created_at',
            ])
            ->where('status', FederationWorkflowStatus::Pending->value)
            ->orderBy('created_at')
            ->get()
            ->map(function (FederationCoalitionInvitation $invitation): StaffWorkItem {
                $isLocalReview = $invitation->direction === 'inbound';

                return new StaffWorkItem(
                    type: $this->type(),
                    id: 'invitation:'.$invitation->id,
                    typeLabel: 'Federation coalition invitation',
                    subject: 'Federation coalition invitation awaiting review',
                    createdAt: $invitation->created_at,
                    ownerKey: null,
                    ownerLabel: null,
                    statusLabel: $isLocalReview ? 'Pending local review' : 'Awaiting peer review',
                    statusIntent: $isLocalReview ? 'pending' : 'neutral',
                    statusIcon: 'user-group',
                    nextActionLabel: $isLocalReview ? 'Review coalition invitation' : 'Review coalition status',
                    url: $this->federationUrl('coalitions'),
                    dueAt: $invitation->expires_at,
                    urgencyHint: $this->urgencyFor($invitation->expires_at),
                    searchTerms: [$invitation->id, (string) $invitation->direction],
                );
            });

        $proposals = FederationCoalitionProposal::query()
            ->select([
                'id',
                'status',
                'expires_at',
                'created_at',
            ])
            ->where('status', FederationWorkflowStatus::Pending->value)
            ->orderBy('created_at')
            ->get()
            ->map(fn (FederationCoalitionProposal $proposal): StaffWorkItem => new StaffWorkItem(
                type: $this->type(),
                id: 'proposal:'.$proposal->id,
                typeLabel: 'Federation coalition proposal',
                subject: 'Federation coalition roster proposal awaiting review',
                createdAt: $proposal->created_at,
                ownerKey: null,
                ownerLabel: null,
                statusLabel: 'Pending review',
                statusIntent: 'pending',
                statusIcon: 'clipboard-document-check',
                nextActionLabel: 'Review coalition proposal',
                url: $this->federationUrl('coalitions'),
                dueAt: $proposal->expires_at,
                urgencyHint: $this->urgencyFor($proposal->expires_at),
                searchTerms: [$proposal->id],
            ));

        return $invitations
            ->concat($proposals)
            ->sortBy(fn (StaffWorkItem $item): int => $item->createdAt->getTimestamp())
            ->values()
            ->all();
    }

    /**
     * @return list<StaffWorkItem>
     */
    private function loadReceivedReviews(): array
    {
        return FederationReceivedVersion::query()
            ->select([
                'id',
                'federation_received_resource_id',
                'disposition',
                'expires_at',
                'created_at',
            ])
            ->where('disposition', ReceivedDisposition::Pending->value)
            ->where('expires_at', '>', now())
            ->whereHas('resource', fn ($query) => $query->where(
                'state',
                ReceivedResourceState::PendingReview->value,
            ))
            ->orderBy('created_at')
            ->get()
            ->map(fn (FederationReceivedVersion $version): StaffWorkItem => new StaffWorkItem(
                type: $this->type(),
                id: $version->id,
                typeLabel: 'Received federation plan',
                subject: 'Received federation plan awaiting review',
                createdAt: $version->created_at,
                ownerKey: null,
                ownerLabel: null,
                statusLabel: 'Pending review',
                statusIntent: 'pending',
                statusIcon: 'inbox-arrow-down',
                nextActionLabel: 'Review received plan',
                url: $this->federationUrl('received'),
                dueAt: $version->expires_at,
                urgencyHint: $this->urgencyFor($version->expires_at),
                searchTerms: [$version->id],
            ))
            ->all();
    }

    /**
     * @return list<StaffWorkItem>
     */
    private function loadBlockedImports(): array
    {
        return FederationReceivedVersion::query()
            ->select([
                'id',
                'import_state',
                'expires_at',
                'updated_at',
            ])
            ->where('import_state', ImportState::BlockedMissingTargets->value)
            ->orderBy('updated_at')
            ->get()
            ->map(fn (FederationReceivedVersion $version): StaffWorkItem => new StaffWorkItem(
                type: $this->type(),
                id: $version->id,
                typeLabel: 'Federated plan import',
                subject: 'Federated plan import requires missing-target retry',
                createdAt: $version->updated_at,
                ownerKey: null,
                ownerLabel: null,
                statusLabel: 'Blocked on local targets',
                statusIntent: 'warning',
                statusIcon: 'exclamation-triangle',
                nextActionLabel: 'Retry federated import',
                url: $this->federationUrl('received'),
                dueAt: $version->expires_at,
                urgencyHint: $this->urgencyFor($version->expires_at),
                searchTerms: [$version->id],
            ))
            ->all();
    }

    /**
     * @return list<StaffWorkItem>
     */
    private function loadHeldOperations(): array
    {
        return MilcomOperation::query()
            ->select(['id', 'federation_held_at', 'updated_at'])
            ->where('federation_action_required', true)
            ->orderByRaw('COALESCE(federation_held_at, updated_at)')
            ->get()
            ->map(fn (MilcomOperation $operation): StaffWorkItem => new StaffWorkItem(
                type: $this->type(),
                id: $operation->getKey(),
                typeLabel: 'Held federation operation',
                subject: 'Federated operation requires action',
                createdAt: $operation->federation_held_at ?? $operation->updated_at,
                ownerKey: null,
                ownerLabel: null,
                statusLabel: 'Action required',
                statusIntent: 'warning',
                statusIcon: 'hand-raised',
                nextActionLabel: 'Resolve federation hold',
                url: $this->federationUrl('received'),
                searchTerms: [(string) $operation->getKey()],
            ))
            ->all();
    }

    private function federationUrl(string $section): string
    {
        return route('admin.federation.index').'#'.$section;
    }

    private function urgencyFor(?Carbon $expiresAt): ?string
    {
        if ($expiresAt === null) {
            return null;
        }

        return match (true) {
            $expiresAt->isPast() => 'urgent',
            $expiresAt->lte(now()->addHours(6)) => 'attention',
            default => null,
        };
    }
}
