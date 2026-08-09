<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Federation\Services\WarPlanPublicationService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\FederationPublicationPreviewRequest;
use App\Http\Requests\Admin\FederationPublicationPublishRequest;
use App\Http\Requests\Admin\FederationPublicationRevokeRequest;
use App\Models\FederationCoalition;
use App\Models\FederationPublication;
use App\Models\FederationPublicationVersion;
use App\Models\MilcomOperation;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class FederationPublicationController extends Controller
{
    public function preview(
        FederationPublicationPreviewRequest $request,
        WarPlanPublicationService $publications,
    ): RedirectResponse {
        $data = $request->validated();
        $version = $publications->preview(
            operation: MilcomOperation::query()->findOrFail($data['operation_id']),
            coalition: FederationCoalition::query()->findOrFail($data['coalition_id']),
            recipientLinkIds: $data['recipient_link_ids'],
            objectiveIds: $data['objective_ids'],
            title: $data['title'],
            waveLabel: $data['wave_label'] ?? '',
            recipientInstructions: $data['recipient_instructions'] ?? '',
            expiresAt: CarbonImmutable::parse($data['expires_at']),
            actorUserId: (int) $request->user()->id,
            publication: isset($data['publication_id'])
                ? FederationPublication::query()->findOrFail($data['publication_id'])
                : null,
        );

        return redirect()->route('admin.federation.publications.preview.show', $version);
    }

    public function showPreview(FederationPublicationVersion $version): View
    {
        $this->authorize('publish-federated-war-plans');
        $this->authorize('manage-war-room');
        $version->load(['publication.operation', 'publication.coalition', 'deliveries.link']);

        return view('admin.federation.preview', [
            'version' => $version,
            'excludedCategories' => [
                'Friendly alliances, nations, participants, assignments, declared wars, and attack progress',
                'Threat and match scores, confidence, recommendations, alternatives, warnings, factors, and overrides',
                'Military, readiness, resource, activity, and project data',
                'Existing war reasons and all Discord identifiers, rooms, mentions, tags, and links',
                'Creator identities, local record identifiers, metadata, failures, event payloads, and internal URLs',
            ],
        ]);
    }

    public function publish(
        FederationPublicationPublishRequest $request,
        FederationPublicationVersion $version,
        WarPlanPublicationService $publications,
    ): RedirectResponse {
        $published = $publications->publish($version, $request->validated('preview_hash'));

        return redirect()->route('admin.federation.index')->with([
            'alert-message' => "Federation publication version {$published->version} queued for delivery.",
            'alert-type' => 'success',
        ]);
    }

    public function revokeRecipient(
        FederationPublicationRevokeRequest $request,
        FederationPublication $publication,
        WarPlanPublicationService $publications,
    ): RedirectResponse {
        $publications->revokeRecipient(
            $publication,
            $request->validated('recipient_installation_id'),
            $request->validated('reason_code'),
            (int) $request->user()->id,
        );

        return $this->back('Recipient access revoked and tombstone queued.');
    }

    public function revokeAll(
        FederationPublicationRevokeRequest $request,
        FederationPublication $publication,
        WarPlanPublicationService $publications,
    ): RedirectResponse {
        $publications->revokeAll(
            $publication,
            $request->validated('reason_code'),
            (int) $request->user()->id,
        );

        return $this->back('Publication revoked for every recipient.');
    }

    private function back(string $message): RedirectResponse
    {
        return redirect()->route('admin.federation.index')->with([
            'alert-message' => $message,
            'alert-type' => 'success',
        ]);
    }
}
