<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Federation\Enums\CoalitionRole;
use App\Domain\Federation\Services\FederationCoalitionService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\FederationCoalitionRequest;
use App\Models\FederationCoalition;
use App\Models\FederationCoalitionInvitation;
use App\Models\FederationCoalitionMembership;
use App\Models\FederationCoalitionProposal;
use App\Models\FederationLink;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;

class FederationCoalitionController extends Controller
{
    public function store(
        FederationCoalitionRequest $request,
        FederationCoalitionService $coalitions,
    ): RedirectResponse {
        $coalitions->create(
            $request->validated('name'),
            $request->filled('expires_at')
                ? CarbonImmutable::parse($request->validated('expires_at'))
                : null,
            (int) $request->user()->id,
        );

        return $this->back('Coalition created with canonical roster revision 1.');
    }

    public function invite(
        FederationCoalitionRequest $request,
        FederationCoalition $coalition,
        FederationCoalitionService $coalitions,
    ): RedirectResponse {
        $link = FederationLink::query()->findOrFail($request->validated('link_id'));
        $coalitions->invite(
            $coalition,
            $link,
            CoalitionRole::from($request->validated('role')),
            (int) $request->user()->id,
        );

        return $this->back('Coalition invitation queued. Membership grants no capability by itself.');
    }

    public function accept(
        FederationCoalitionRequest $request,
        FederationCoalitionInvitation $invitation,
        FederationCoalitionService $coalitions,
    ): RedirectResponse {
        $coalitions->acceptInvitation($invitation, (int) $request->user()->id);

        return $this->back('Coalition invitation accepted locally. Awaiting the coordinator roster.');
    }

    public function remove(
        FederationCoalitionRequest $request,
        FederationCoalition $coalition,
        FederationCoalitionMembership $membership,
        FederationCoalitionService $coalitions,
    ): RedirectResponse {
        $coalitions->removeMember($coalition, $membership, (int) $request->user()->id);

        return $this->back('Member removed and source-owned publications queued for revocation.');
    }

    public function dissolve(
        FederationCoalitionRequest $request,
        FederationCoalition $coalition,
        FederationCoalitionService $coalitions,
    ): RedirectResponse {
        $coalitions->dissolve($coalition, (int) $request->user()->id);

        return $this->back('Coalition dissolved and tombstones queued.');
    }

    public function propose(
        FederationCoalitionRequest $request,
        FederationCoalition $coalition,
        FederationCoalitionService $coalitions,
    ): RedirectResponse {
        $data = $request->validated();
        $requestedRole = $data['proposal_type'] === 'coordinator.transfer'
            ? CoalitionRole::Coordinator
            : (isset($data['requested_role']) ? CoalitionRole::from($data['requested_role']) : null);
        $coalitions->proposeRosterChange(
            $coalition,
            $data['proposal_type'],
            $data['target_installation_id'] ?? null,
            $requestedRole,
            (int) $request->user()->id,
        );

        return $this->back('Coalition roster proposal submitted. Coordinator approval is required.');
    }

    public function approveProposal(
        FederationCoalitionRequest $request,
        FederationCoalitionProposal $proposal,
        FederationCoalitionService $coalitions,
    ): RedirectResponse {
        $coalitions->approveProposal($proposal, (int) $request->user()->id);

        return $this->back('Coalition proposal approved and a new roster queued.');
    }

    public function rejectProposal(
        FederationCoalitionRequest $request,
        FederationCoalitionProposal $proposal,
        FederationCoalitionService $coalitions,
    ): RedirectResponse {
        $coalitions->rejectProposal(
            $proposal,
            (int) $request->user()->id,
            $request->validated('reason_code', 'rejected_by_coordinator'),
        );

        return $this->back('Coalition proposal rejected.');
    }

    public function acceptTransfer(
        FederationCoalitionRequest $request,
        FederationCoalitionProposal $proposal,
        FederationCoalitionService $coalitions,
    ): RedirectResponse {
        $coalitions->acceptCoordinatorTransfer($proposal, (int) $request->user()->id);

        return $this->back('Coordinator transfer accepted by this installation.');
    }

    public function approveTransfer(
        FederationCoalitionRequest $request,
        FederationCoalitionProposal $proposal,
        FederationCoalitionService $coalitions,
    ): RedirectResponse {
        $coalitions->approveCoordinatorTransfer($proposal, (int) $request->user()->id);

        return $this->back('Coordinator transfer approved. The dual-signed control exchange was queued.');
    }

    private function back(string $message): RedirectResponse
    {
        return redirect()->route('admin.federation.index')->with([
            'alert-message' => $message,
            'alert-type' => 'success',
        ]);
    }
}
