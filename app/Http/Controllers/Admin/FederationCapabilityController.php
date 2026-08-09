<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Federation\Enums\CapabilityDirection;
use App\Domain\Federation\Enums\CapabilityState;
use App\Domain\Federation\Services\FederationCapabilityService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\FederationCapabilityRequest;
use App\Models\FederationCoalition;
use App\Models\FederationLink;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;

class FederationCapabilityController extends Controller
{
    public function store(
        FederationCapabilityRequest $request,
        FederationCoalition $coalition,
        FederationCapabilityService $capabilities,
    ): RedirectResponse {
        $link = FederationLink::query()->findOrFail($request->validated('link_id'));
        $state = CapabilityState::from($request->validated('state'));
        $capabilities->set(
            coalition: $coalition,
            link: $link,
            direction: CapabilityDirection::from($request->validated('direction')),
            state: $state,
            expiresAt: $request->filled('expires_at')
                ? CarbonImmutable::parse($request->validated('expires_at'))
                : null,
            actorUserId: (int) $request->user()->id,
        );

        return redirect()->route('admin.federation.index')->with([
            'alert-message' => 'Directional capability revision published.',
            'alert-type' => 'success',
        ]);
    }
}
