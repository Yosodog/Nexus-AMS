<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Federation\DTO\WarPlanSnapshotV1;
use App\Domain\Federation\Resources\WarPlanSnapshotDiff;
use App\Http\Controllers\Controller;
use App\Models\FederationCoalition;
use App\Models\FederationIdentity;
use App\Models\FederationInboxMessage;
use App\Models\FederationLink;
use App\Models\FederationOutboxMessage;
use App\Models\FederationPublication;
use App\Models\FederationReceivedResource;
use App\Models\MilcomOperation;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Throwable;

class FederationController extends Controller
{
    public function __construct(private readonly WarPlanSnapshotDiff $snapshotDiff) {}

    public function index(): View
    {
        $this->authorize('view-federation');
        $user = request()->user();
        $canDiagnose = $user?->can('view-federation-diagnostics') === true;
        $canReviewReceivedPlans = $user?->can('review-federated-war-plans') === true;

        $receivedResources = $canReviewReceivedPlans
            ? FederationReceivedResource::query()
                ->with(['link.activePeerKey', 'versions.importedOperation'])
                ->latest('updated_at')
                ->limit(100)
                ->get()
            : new Collection;

        return view('admin.federation.index', [
            'identity' => FederationIdentity::query()->with(['activeKey', 'keys'])->first(),
            'links' => FederationLink::query()
                ->with(['activePeerKey', 'peerKeys', 'invitations'])
                ->orderBy('remote_display_name')
                ->get(),
            'coalitions' => FederationCoalition::query()
                ->with(['memberships.link', 'invitations', 'proposals', 'capabilities'])
                ->latest('created_at')
                ->get(),
            'publications' => FederationPublication::query()
                ->with(['operation', 'coalition', 'versions.deliveries.link'])
                ->latest('created_at')
                ->limit(50)
                ->get(),
            'receivedResources' => $receivedResources,
            'receivedReviews' => $receivedResources->flatMap(function ($resource) {
                $previous = null;

                return $resource->versions
                    ->sortBy('version')
                    ->map(function ($version) use ($resource, &$previous): array {
                        $snapshot = null;

                        if ($version->canonical_payload !== null) {
                            try {
                                $snapshot = WarPlanSnapshotV1::fromJson((string) $version->canonical_payload);
                            } catch (Throwable) {
                                $snapshot = null;
                            }
                        }

                        $row = [
                            'resource' => $resource,
                            'version' => $version,
                            'snapshot' => $snapshot,
                            'diff' => $snapshot instanceof WarPlanSnapshotV1
                                ? $this->snapshotDiff->between($previous, $snapshot)
                                : null,
                        ];

                        if ($snapshot instanceof WarPlanSnapshotV1) {
                            $previous = $snapshot;
                        }

                        return $row;
                    });
            })->sortByDesc(fn (array $row) => $row['version']->created_at)->values(),
            'eligibleOperations' => MilcomOperation::query()
                ->plans()
                ->operational()
                ->with(['objectives' => fn ($query) => $query->orderBy('target_nation_id')])
                ->latest('updated_at')
                ->limit(100)
                ->get(),
            'diagnostics' => $canDiagnose ? [
                'oldest_outbox_at' => FederationOutboxMessage::query()
                    ->whereIn('status', ['pending', 'delivering'])
                    ->min('created_at'),
                'pending_outbox' => FederationOutboxMessage::query()
                    ->whereIn('status', ['pending', 'delivering'])
                    ->count(),
                'quarantined_inbox' => FederationInboxMessage::query()
                    ->where('status', 'quarantined')
                    ->count(),
                'oldest_inbox_at' => FederationInboxMessage::query()
                    ->whereIn('status', ['accepted', 'processing'])
                    ->min('created_at'),
            ] : null,
            'discoveryPreview' => session('federation_discovery'),
        ]);
    }
}
