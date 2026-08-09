<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Federation\Services\FederationLinkService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\FederationLinkRequest;
use App\Http\Requests\Admin\FederationReasonRequest;
use App\Models\FederationLink;
use App\Models\FederationLinkInvitation;
use App\Models\FederationPeerKey;
use Illuminate\Http\RedirectResponse;

class FederationLinkController extends Controller
{
    public function discover(
        FederationLinkRequest $request,
        FederationLinkService $links,
    ): RedirectResponse {
        $discovery = $links->discover($request->validated('origin'));

        return redirect()->route('admin.federation.index')->with(
            'federation_discovery',
            $discovery->toArray()
        );
    }

    public function store(
        FederationLinkRequest $request,
        FederationLinkService $links,
    ): RedirectResponse {
        $links->begin(
            $request->validated('origin'),
            (int) $request->user()->id,
            $request->boolean('fingerprints_confirmed'),
        );

        return $this->back('Link request queued. The peer must approve it before final local activation.');
    }

    public function approve(
        FederationLinkRequest $request,
        FederationLinkInvitation $invitation,
        FederationLinkService $links,
    ): RedirectResponse {
        $links->approveIncoming($invitation, (int) $request->user()->id);

        return $this->back('Incoming link approved. Acceptance was queued for the peer.');
    }

    public function activate(
        FederationLinkRequest $request,
        FederationLinkInvitation $invitation,
        FederationLinkService $links,
    ): RedirectResponse {
        $links->finalizeOutgoing($invitation, (int) $request->user()->id);

        return $this->back('Final link activation was queued.');
    }

    public function suspend(
        FederationReasonRequest $request,
        FederationLink $link,
        FederationLinkService $links,
    ): RedirectResponse {
        $links->suspend($link, $request->validated('reason_code'));

        return $this->back('Link suspended immediately.');
    }

    public function revoke(
        FederationReasonRequest $request,
        FederationLink $link,
        FederationLinkService $links,
    ): RedirectResponse {
        $links->revoke($link, $request->validated('reason_code'));

        return $this->back('Link revoked. Relinking requires a new workflow.');
    }

    public function proposeEndpoint(
        FederationLinkRequest $request,
        FederationLink $link,
        FederationLinkService $links,
    ): RedirectResponse {
        $links->proposeEndpointChange(
            $link,
            $request->validated('new_origin'),
            (int) $request->user()->id,
        );

        return $this->back('Signed endpoint change proposed. The pinned origin remains unchanged until approval.');
    }

    public function approveEndpoint(
        FederationLinkRequest $request,
        FederationLinkInvitation $proposal,
        FederationLinkService $links,
    ): RedirectResponse {
        $links->approveEndpointChange($proposal, (int) $request->user()->id);

        return $this->back('Endpoint change approved and response queued.');
    }

    public function rejectEndpoint(
        FederationReasonRequest $request,
        FederationLinkInvitation $proposal,
        FederationLinkService $links,
    ): RedirectResponse {
        $links->rejectEndpointChange(
            $proposal,
            (int) $request->user()->id,
            $request->validated('reason_code'),
        );

        return $this->back('Endpoint change rejected. The pinned origin was not changed.');
    }

    public function activateEndpoint(
        FederationLinkRequest $request,
        FederationLinkInvitation $proposal,
        FederationLinkService $links,
    ): RedirectResponse {
        $links->activateEndpointChange($proposal, (int) $request->user()->id);

        return $this->back('Approved endpoint change activated.');
    }

    public function reapproveKey(
        FederationLinkRequest $request,
        FederationLink $link,
        FederationPeerKey $key,
        FederationLinkService $links,
    ): RedirectResponse {
        abort_unless($key->federation_link_id === $link->id, 404);
        $links->reapprovePeerKey(
            $link,
            [
                'key_id' => $key->remote_key_id,
                'generation' => (int) $key->generation,
                'signing_public_key' => $key->signing_public_key,
                'box_public_key' => $key->box_public_key,
                'signing_fingerprint' => $key->signing_fingerprint,
                'box_fingerprint' => $key->box_fingerprint,
            ],
            (int) $request->user()->id,
            $request->boolean('fingerprints_confirmed'),
        );

        return $this->back('Replacement peer fingerprints approved. Mutual activation is still required.');
    }

    public function stageRecoveryKey(
        FederationLinkRequest $request,
        FederationLink $link,
        FederationLinkService $links,
    ): RedirectResponse {
        $links->stagePeerRecoveryKey($link, (int) $request->user()->id);

        return $this->back('The pinned peer discovery key was staged. Compare both fingerprints out of band before approval.');
    }

    private function back(string $message): RedirectResponse
    {
        return redirect()->route('admin.federation.index')->with([
            'alert-message' => $message,
            'alert-type' => 'success',
        ]);
    }
}
