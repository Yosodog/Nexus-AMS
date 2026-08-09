<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Federation\Services\FederationIdentityService;
use App\Domain\Federation\Services\FederationLinkService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\FederationIdentityRequest;
use App\Models\FederationIdentityKey;
use Illuminate\Http\RedirectResponse;

class FederationIdentityController extends Controller
{
    public function enable(
        FederationIdentityRequest $request,
        FederationIdentityService $identities,
    ): RedirectResponse {
        $identities->enable();

        return $this->back('Federation identity enabled.');
    }

    public function disable(
        FederationIdentityRequest $request,
        FederationIdentityService $identities,
    ): RedirectResponse {
        $identities->disable();

        return $this->back('Federation disabled. Identity, keys, links, and history were retained.');
    }

    public function rotate(
        FederationIdentityRequest $request,
        FederationLinkService $links,
    ): RedirectResponse {
        $links->initiateKeyRotation((int) $request->user()->id);

        return $this->back('Key rotation started and queued for active peers.');
    }

    public function transferOwnership(
        FederationIdentityRequest $request,
        FederationLinkService $links,
    ): RedirectResponse {
        $links->transferOwnership((int) $request->user()->id);

        return $this->back('Ownership epoch advanced and the required cross-signed key rotation was queued.');
    }

    public function activate(
        FederationIdentityRequest $request,
        FederationIdentityKey $key,
        FederationLinkService $links,
    ): RedirectResponse {
        $links->activateKeyRotation($key, (int) $request->user()->id);

        return $this->back('The acknowledged key generation is now active.');
    }

    public function compromise(
        FederationIdentityRequest $request,
        FederationIdentityKey $key,
        FederationIdentityService $identities,
    ): RedirectResponse {
        $identities->markCompromised($key);

        return $this->back('Key marked compromised. All links were suspended pending fingerprint reapproval.');
    }

    private function back(string $message): RedirectResponse
    {
        return redirect()->route('admin.federation.index')->with([
            'alert-message' => $message,
            'alert-type' => 'success',
        ]);
    }
}
