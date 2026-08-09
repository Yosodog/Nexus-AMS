@extends('admin.settings.layout')

@section('settings-title', 'Nexus Federation')
@section('settings-subtitle', 'Link independent Nexus installations, govern coalition access, and exchange immutable war-plan snapshots directly.')

@php
    $identityId = $identity?->id;
    $statusTone = static fn (string $status): string => match ($status) {
        'active', 'accepted', 'imported', 'processed', 'validated', 'published' => 'badge-success',
        'pending', 'pending_local', 'pending_remote', 'pending_review', 'queued', 'processing', 'transport_accepted' => 'badge-info',
        'suspended', 'blocked_missing_targets', 'partially_revoked', 'expired' => 'badge-warning',
        'revoked', 'rejected', 'failed', 'quarantined' => 'badge-error',
        default => 'badge-ghost',
    };
    $activeLinks = $links->filter(fn ($link) => $link->status->value === 'active');
    $activeCoalitions = $coalitions->filter(fn ($coalition) => $coalition->status->value === 'active');
    $pendingLinkInvitations = $links->flatMap->invitations->filter(
        fn ($invitation) => $invitation->status->value === 'pending'
            && in_array($invitation->direction, ['inbound', 'outbound'], true)
    );
    $endpointProposals = $links->flatMap->invitations->filter(
        fn ($invitation) => in_array($invitation->direction, ['endpoint_inbound', 'endpoint_outbound'], true)
            && in_array($invitation->status->value, ['pending', 'approved'], true)
    );
    $pendingCoalitionInvitations = $coalitions->flatMap->invitations->filter(
        fn ($invitation) => $invitation->direction === 'inbound' && $invitation->status->value === 'pending'
    );
@endphp

@section('settings-content')
    <nav class="nexus-panel overflow-x-auto" aria-label="Federation sections">
        <div class="tabs tabs-border min-w-max px-2 sm:px-3">
            @foreach ([
                ['identity', 'Identity'],
                ['links', 'Links'],
                ['coalitions', 'Coalitions & capabilities'],
                ['publish', 'Publish plans'],
                ['received', 'Received plans'],
                ['diagnostics', 'Diagnostics'],
            ] as [$anchor, $label])
                @if ($anchor !== 'received' || auth()->user()->can('review-federated-war-plans'))
                    <a href="#{{ $anchor }}" class="tab h-12 whitespace-nowrap">{{ $label }}</a>
                @endif
            @endforeach
        </div>
    </nav>

    @if (! config('federation.enabled'))
        <div class="alert alert-warning items-start" role="alert">
            <x-icon name="o-lock-closed" class="mt-0.5 size-5 shrink-0" aria-hidden="true" />
            <div>
                <p class="font-semibold">Federation is unavailable at the server gate.</p>
                <p class="mt-1 text-sm">Set <code>FEDERATION_ENABLED=true</code> before an administrator can generate this installation's identity. Publishing remains separately disabled.</p>
            </div>
        </div>
    @endif

    <section id="identity" class="nexus-panel scroll-mt-24" aria-labelledby="federation-identity-title">
        <div class="nexus-panel__header">
            <div>
                <h2 id="federation-identity-title" class="nexus-section-title">Installation identity</h2>
                <p class="mt-1 text-sm text-base-content/65">This Nexus installation owns its identity and private key generations. Nexus Cloud is not involved.</p>
            </div>
            <span class="badge {{ $identity?->enabled ? 'badge-success' : 'badge-ghost' }}">
                {{ $identity?->enabled ? 'Enabled' : 'Disabled' }}
            </span>
        </div>

        <div class="nexus-panel__body space-y-6">
            @if ($identity)
                <dl class="grid gap-x-8 gap-y-4 md:grid-cols-2 xl:grid-cols-4">
                    <div><dt class="text-xs font-semibold uppercase tracking-wide text-base-content/55">Installation ID</dt><dd class="mt-1 break-all font-mono text-sm">{{ $identity->id }}</dd></div>
                    <div><dt class="text-xs font-semibold uppercase tracking-wide text-base-content/55">Pinned origin</dt><dd class="mt-1 break-all text-sm">{{ $identity->origin }}</dd></div>
                    <div><dt class="text-xs font-semibold uppercase tracking-wide text-base-content/55">Display name</dt><dd class="mt-1 text-sm">{{ $identity->display_name }}</dd></div>
                    <div><dt class="text-xs font-semibold uppercase tracking-wide text-base-content/55">Ownership epoch</dt><dd class="mt-1 font-semibold tabular-nums">{{ number_format($identity->ownership_epoch) }}</dd></div>
                </dl>

                @if ($identity->activeKey)
                    <div class="grid gap-4 lg:grid-cols-2">
                        <div class="rounded-md bg-base-200/70 p-4">
                            <p class="text-xs font-semibold uppercase tracking-wide text-base-content/55">Signing fingerprint</p>
                            <p class="mt-2 break-all font-mono text-sm leading-6">{{ $identity->activeKey->signing_fingerprint }}</p>
                        </div>
                        <div class="rounded-md bg-base-200/70 p-4">
                            <p class="text-xs font-semibold uppercase tracking-wide text-base-content/55">Encryption fingerprint</p>
                            <p class="mt-2 break-all font-mono text-sm leading-6">{{ $identity->activeKey->box_fingerprint }}</p>
                        </div>
                    </div>
                @endif

                @can('manage-federation')
                    <div class="flex flex-wrap items-center gap-3 border-t border-base-300 pt-5">
                        @if ($identity->enabled)
                            <form method="POST" action="{{ route('admin.federation.identity.disable') }}" data-confirm="Disable federation? Identity, keys, links, and history remain stored, but discovery and normal ingress stop." data-confirm-title="Disable federation?" data-confirm-label="Disable" data-confirm-tone="warning">
                                @csrf
                                <button class="btn btn-outline" type="submit">Disable federation</button>
                            </form>
                            <form method="POST" action="{{ route('admin.federation.identity.rotate') }}" data-confirm="Start a manual key rotation? Peers must acknowledge the cross-signed generation before activation." data-confirm-title="Start key rotation?" data-confirm-label="Generate keys">
                                @csrf
                                <button class="btn btn-primary" type="submit">Rotate keys</button>
                            </form>
                            <form method="POST" action="{{ route('admin.federation.identity.transfer-ownership') }}" data-confirm="Advance the ownership epoch and start the required cross-signed key rotation? Every active peer must acknowledge it." data-confirm-title="Transfer installation ownership?" data-confirm-label="Advance ownership epoch" data-confirm-tone="warning">
                                @csrf
                                <button class="btn btn-outline" type="submit">Transfer ownership</button>
                            </form>
                        @else
                            <form method="POST" action="{{ route('admin.federation.identity.enable') }}">
                                @csrf
                                <button class="btn btn-primary" type="submit" @disabled(! config('federation.enabled'))>Enable federation</button>
                            </form>
                        @endif
                    </div>
                @endcan

                @if ($identity->keys->isNotEmpty())
                    <div class="overflow-x-auto">
                        <table class="table table-sm">
                            <thead><tr><th>Generation</th><th>Status</th><th>Created</th><th>Lifecycle</th></tr></thead>
                            <tbody>
                                @foreach ($identity->keys->sortByDesc('generation') as $key)
                                    <tr>
                                        <td class="font-semibold tabular-nums">{{ $key->generation }}</td>
                                        <td><span class="badge {{ $statusTone($key->status->value) }} badge-sm">{{ str($key->status->value)->headline() }}</span></td>
                                        <td>{{ $key->created_at?->diffForHumans() }}</td>
                                        <td>
                                            @can('manage-federation')
                                                <div class="flex flex-wrap gap-2">
                                                    @if ($key->status->value === 'pending')
                                                        <form method="POST" action="{{ route('admin.federation.identity.keys.activate', $key) }}" data-confirm="Activate this generation only after every active peer acknowledges it." data-confirm-title="Activate key generation?" data-confirm-label="Activate">
                                                            @csrf
                                                            <button class="btn btn-xs btn-outline" type="submit">Activate</button>
                                                        </form>
                                                    @endif
                                                    @if (! in_array($key->status->value, ['compromised', 'retired'], true))
                                                        <form method="POST" action="{{ route('admin.federation.identity.keys.compromise', $key) }}" data-confirm="Mark this key compromised? Every link will suspend immediately and fingerprint reapproval will be required." data-confirm-title="Declare key compromise?" data-confirm-label="Suspend all links" data-confirm-tone="error">
                                                            @csrf
                                                            <button class="btn btn-xs btn-error btn-outline" type="submit">Compromised</button>
                                                        </form>
                                                    @endif
                                                </div>
                                            @endcan
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            @else
                <div class="py-8 text-center">
                    <h3 class="font-semibold">No federation identity exists</h3>
                    <p class="mx-auto mt-2 max-w-2xl text-sm text-base-content/65">Enabling federation creates one installation ULID plus separate Ed25519 signing and X25519 encryption keys. Private material is encrypted at rest.</p>
                    @can('manage-federation')
                        <form method="POST" action="{{ route('admin.federation.identity.enable') }}" class="mt-5">
                            @csrf
                            <button class="btn btn-primary" type="submit" @disabled(! config('federation.enabled'))>Generate installation identity</button>
                        </form>
                    @endcan
                </div>
            @endif
        </div>
    </section>

    <section id="links" class="nexus-panel scroll-mt-24" aria-labelledby="federation-links-title">
        <div class="nexus-panel__header">
            <div><h2 id="federation-links-title" class="nexus-section-title">Bilateral links</h2><p class="mt-1 text-sm text-base-content/65">Both administrators approve pinned origins and compare signing and encryption fingerprints out of band.</p></div>
            <span class="badge badge-outline">{{ $activeLinks->count() }} active</span>
        </div>

        @can('manage-federation')
            <div class="nexus-panel__body border-b border-base-300">
                <form method="POST" action="{{ route('admin.federation.links.discover') }}" class="grid items-end gap-4 lg:grid-cols-[minmax(0,1fr)_auto]">
                    @csrf
                    <label class="block">
                        <span class="label px-0">Peer HTTPS origin</span>
                        <input class="input w-full" type="url" name="origin" value="{{ old('origin', data_get($discoveryPreview, 'origin')) }}" placeholder="https://nexus.example.org" maxlength="512" required>
                        <span class="mt-1 block text-xs nexus-text-muted">Discovery never changes a previously pinned origin automatically.</span>
                    </label>
                    <button class="btn btn-outline" type="submit" @disabled(! $identity?->enabled || ! config('federation.features.linking'))>Fetch public fingerprints</button>
                </form>

                @if ($discoveryPreview)
                    <form method="POST" action="{{ route('admin.federation.links.store') }}" class="mt-5 rounded-md border border-base-300 p-4">
                        @csrf
                        <input type="hidden" name="origin" value="{{ data_get($discoveryPreview, 'origin') }}">
                        <div class="grid gap-4 lg:grid-cols-3">
                            <div><p class="text-xs font-semibold uppercase tracking-wide text-base-content/55">Installation</p><p class="mt-1 break-all text-sm">{{ data_get($discoveryPreview, 'installation_id') }}</p></div>
                            <div><p class="text-xs font-semibold uppercase tracking-wide text-base-content/55">Signing</p><p class="mt-1 break-all font-mono text-xs leading-5">{{ data_get($discoveryPreview, 'current_key.signing_fingerprint') }}</p></div>
                            <div><p class="text-xs font-semibold uppercase tracking-wide text-base-content/55">Encryption</p><p class="mt-1 break-all font-mono text-xs leading-5">{{ data_get($discoveryPreview, 'current_key.box_fingerprint') }}</p></div>
                        </div>
                        <label class="mt-4 flex cursor-pointer items-start gap-3">
                            <input class="checkbox checkbox-primary mt-0.5" type="checkbox" name="fingerprints_confirmed" value="1" required>
                            <span class="text-sm">I compared both fingerprints with the peer administrator through a separate trusted channel.</span>
                        </label>
                        <button class="btn btn-primary mt-4" type="submit">Send targeted invitation</button>
                    </form>
                @endif
            </div>
        @endcan

        @if ($pendingLinkInvitations->isNotEmpty())
            <div class="border-b border-base-300 p-5">
                <h3 class="font-semibold">Pending approvals</h3>
                <div class="mt-3 grid gap-3">
                    @foreach ($pendingLinkInvitations as $invitation)
                        @php $link = $links->firstWhere('id', $invitation->federation_link_id); @endphp
                        <div class="flex flex-col gap-3 rounded-md bg-base-200/65 p-4 lg:flex-row lg:items-center lg:justify-between">
                            <div>
                                <p class="font-semibold">{{ $link?->remote_display_name ?: $invitation->peer_origin }}</p>
                                <p class="mt-1 text-xs nexus-text-muted">{{ ucfirst($invitation->direction) }} · expires {{ $invitation->expires_at->diffForHumans() }}</p>
                            </div>
                            @can('manage-federation')
                                @if ($invitation->direction === 'inbound')
                                    <form method="POST" action="{{ route('admin.federation.links.approve', $invitation) }}" data-confirm="Approve only after comparing both displayed peer fingerprints through a trusted channel." data-confirm-title="Approve incoming link?" data-confirm-label="Approve link">
                                        @csrf
                                        <button class="btn btn-sm btn-primary" type="submit">Approve incoming link</button>
                                    </form>
                                @else
                                    <form method="POST" action="{{ route('admin.federation.links.activate', $invitation) }}" data-confirm="Perform final local approval and queue activation for this peer?" data-confirm-title="Finalize link?" data-confirm-label="Activate link">
                                        @csrf
                                        <button class="btn btn-sm btn-primary" type="submit">Final approval</button>
                                    </form>
                                @endif
                            @endcan
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        @if ($endpointProposals->isNotEmpty())
            <div class="border-b border-base-300 p-5">
                <h3 class="font-semibold">Endpoint changes awaiting action</h3>
                <div class="mt-3 grid gap-3">
                    @foreach ($endpointProposals as $proposal)
                        @php
                            $proposalLink = $links->firstWhere('id', $proposal->federation_link_id);
                        @endphp
                        <div class="flex flex-col gap-3 rounded-md bg-base-200/65 p-4 lg:flex-row lg:items-center lg:justify-between">
                            <div><p class="font-semibold">{{ $proposalLink?->remote_display_name ?: $proposalLink?->remote_installation_id }}</p><p class="mt-1 break-all text-xs nexus-text-muted">{{ $proposalLink?->approved_origin }} → {{ $proposal->peer_origin }}</p></div>
                            <div class="flex flex-wrap gap-2">
                                @if ($proposal->direction === 'endpoint_inbound' && $proposal->status->value === 'pending')
                                    <form method="POST" action="{{ route('admin.federation.links.endpoint.approve', $proposal) }}" data-confirm="Approve this signed endpoint proposal? The pinned origin changes only after activation." data-confirm-title="Approve endpoint change?" data-confirm-label="Approve">@csrf<button class="btn btn-sm btn-primary" type="submit">Approve</button></form>
                                    <form method="POST" action="{{ route('admin.federation.links.endpoint.reject', $proposal) }}">@csrf<input type="hidden" name="reason_code" value="administrator_rejected"><button class="btn btn-sm btn-error btn-outline" type="submit">Reject</button></form>
                                @elseif ($proposal->direction === 'endpoint_outbound' && $proposal->status->value === 'approved')
                                    <form method="POST" action="{{ route('admin.federation.links.endpoint.activate', $proposal) }}" data-confirm="Activate the remotely approved origin now? Future delivery retries resolve and pin this hostname." data-confirm-title="Activate endpoint?" data-confirm-label="Activate">@csrf<button class="btn btn-sm btn-primary" type="submit">Activate endpoint</button></form>
                                @else
                                    <span class="badge badge-info">Awaiting peer</span>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        <div class="overflow-x-auto">
            <table class="table">
                <thead><tr><th>Peer</th><th>Status</th><th>Fingerprints</th><th>Contact</th><th>Actions</th></tr></thead>
                <tbody>
                    @forelse ($links as $link)
                        @php $peerKey = $link->peerKeys->sortByDesc('generation')->first(); @endphp
                        <tr>
                            <td><p class="font-semibold">{{ $link->remote_display_name ?: 'Unnamed installation' }}</p><p class="mt-1 break-all text-xs nexus-text-muted">{{ $link->approved_origin }} · {{ $link->remote_installation_id }}</p></td>
                            <td><span class="badge {{ $statusTone($link->status->value) }} badge-sm">{{ str($link->status->value)->headline() }}</span><p class="mt-1 text-xs nexus-text-muted">Protocol {{ $link->negotiated_protocol_version ?: '—' }}</p></td>
                            <td class="min-w-64"><p class="break-all font-mono text-[0.7rem]">S: {{ $peerKey?->signing_fingerprint ?: 'Not approved' }}</p><p class="mt-1 break-all font-mono text-[0.7rem]">E: {{ $peerKey?->box_fingerprint ?: 'Not approved' }}</p></td>
                            <td class="text-sm">{{ $link->last_contact_at?->diffForHumans() ?: 'Never' }}<p class="mt-1 text-xs nexus-text-muted">Reconciled {{ $link->last_reconciled_at?->diffForHumans() ?: 'never' }}</p></td>
                            <td>
                                @can('manage-federation')
                                    @if (! $link->status->isTerminal())
                                        <div class="flex min-w-48 flex-col gap-2">
                                            @if ($link->status->value === 'active')
                                                <form method="POST" action="{{ route('admin.federation.links.suspend', $link) }}" class="flex gap-2" data-confirm="Suspend this link immediately? Normal inbound and outbound messages will stop." data-confirm-title="Suspend link?" data-confirm-label="Suspend" data-confirm-tone="warning">
                                                    @csrf
                                                    <input class="input input-sm min-w-0" name="reason_code" value="administrator_suspended" aria-label="Suspension reason code" required>
                                                    <button class="btn btn-sm btn-outline" type="submit">Suspend</button>
                                                </form>
                                                <form method="POST" action="{{ route('admin.federation.links.endpoint.propose', $link) }}" class="flex gap-2">
                                                    @csrf
                                                    <input class="input input-sm min-w-0" type="url" name="new_origin" placeholder="https://new.example.org" aria-label="Proposed peer origin" required>
                                                    <button class="btn btn-sm btn-outline" type="submit">Propose origin</button>
                                                </form>
                                            @endif
                                            @if ($link->status->value === 'suspended')
                                                <form method="POST" action="{{ route('admin.federation.links.keys.stage-recovery', $link) }}" data-confirm="Fetch the current key only from this link's pinned HTTPS origin? It will remain untrusted until you compare both fingerprints out of band." data-confirm-title="Fetch recovery key?" data-confirm-label="Fetch key">
                                                    @csrf
                                                    <button class="btn btn-xs btn-outline w-full" type="submit">Fetch key from pinned origin</button>
                                                </form>
                                                @php
                                                    $reapprovalKeys = $link->peerKeys
                                                        ->filter(fn ($candidate) => $candidate->status->value === 'pending')
                                                        ->sortByDesc('generation');
                                                    if ($reapprovalKeys->isEmpty() && $link->suspension_reason_code === 'local_key_compromised' && $peerKey) {
                                                        $reapprovalKeys = collect([$peerKey]);
                                                    }
                                                @endphp
                                                @foreach ($reapprovalKeys as $pendingPeerKey)
                                                    <form method="POST" action="{{ route('admin.federation.links.keys.reapprove', [$link, $pendingPeerKey]) }}" class="rounded-md bg-base-200 p-2" data-confirm="Approve this replacement peer key only after comparing both fingerprints through a trusted channel." data-confirm-title="Reapprove peer key?" data-confirm-label="Approve key">
                                                        @csrf
                                                        <p class="break-all font-mono text-[0.65rem]">S: {{ $pendingPeerKey->signing_fingerprint }}</p>
                                                        <p class="mt-1 break-all font-mono text-[0.65rem]">E: {{ $pendingPeerKey->box_fingerprint }}</p>
                                                        <label class="flex items-start gap-2 text-xs"><input class="checkbox checkbox-xs mt-0.5" type="checkbox" name="fingerprints_confirmed" value="1" required><span>I compared generation {{ $pendingPeerKey->generation }} fingerprints out of band.</span></label>
                                                        <button class="btn btn-xs btn-primary mt-2" type="submit">Reapprove key</button>
                                                    </form>
                                                @endforeach
                                            @endif
                                            <form method="POST" action="{{ route('admin.federation.links.revoke', $link) }}" class="flex gap-2" data-confirm="Revoke this link permanently? Reconnecting requires a new invitation and fingerprint verification." data-confirm-title="Revoke link?" data-confirm-label="Revoke" data-confirm-tone="error">
                                                @csrf
                                                <input class="input input-sm min-w-0" name="reason_code" value="administrator_revoked" aria-label="Revocation reason code" required>
                                                <button class="btn btn-sm btn-error btn-outline" type="submit">Revoke</button>
                                            </form>
                                        </div>
                                    @endif
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="py-10 text-center"><p class="font-semibold">No peers linked</p><p class="mt-1 text-sm nexus-text-muted">Fetch a peer's discovery document and compare both fingerprints to begin.</p></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section id="coalitions" class="nexus-panel scroll-mt-24" aria-labelledby="federation-coalitions-title">
        <div class="nexus-panel__header">
            <div><h2 id="federation-coalitions-title" class="nexus-section-title">Coalitions and capabilities</h2><p class="mt-1 text-sm text-base-content/65">Membership proves common scope. Directional capabilities remain separately denied until explicitly granted.</p></div>
            <span class="badge badge-outline">{{ $activeCoalitions->count() }} active</span>
        </div>

        @can('manage-coalitions')
            <div class="nexus-panel__body border-b border-base-300">
                <form method="POST" action="{{ route('admin.federation.coalitions.store') }}" class="grid items-end gap-4 md:grid-cols-[minmax(0,1fr)_minmax(13rem,0.5fr)_auto]">
                    @csrf
                    <label><span class="label px-0">Coalition name</span><input class="input w-full" name="name" maxlength="255" required></label>
                    <label><span class="label px-0">Optional expiry</span><input class="input w-full" type="datetime-local" name="expires_at"></label>
                    <button class="btn btn-primary" type="submit" @disabled(! $identity?->enabled)>Create coalition</button>
                </form>
            </div>
        @endcan

        @if ($pendingCoalitionInvitations->isNotEmpty())
            <div class="border-b border-base-300 p-5">
                <h3 class="font-semibold">Invitations awaiting local review</h3>
                <div class="mt-3 flex flex-wrap gap-3">
                    @foreach ($pendingCoalitionInvitations as $invitation)
                        <form method="POST" action="{{ route('admin.federation.coalitions.accept', $invitation) }}" class="rounded-md bg-base-200/65 p-4" data-confirm="Accept this coalition invitation? No sharing capability is granted automatically." data-confirm-title="Join coalition?" data-confirm-label="Accept invitation">
                            @csrf
                            <p class="font-semibold">{{ $invitation->coalition?->name }}</p>
                            <p class="mt-1 text-xs nexus-text-muted">Role {{ $invitation->role->value }} · expires {{ $invitation->expires_at->diffForHumans() }}</p>
                            <button class="btn btn-sm btn-primary mt-3" type="submit">Accept invitation</button>
                        </form>
                    @endforeach
                </div>
            </div>
        @endif

        <div class="divide-y divide-base-300">
            @forelse ($coalitions as $coalition)
                <details class="group" @if ($loop->first) open @endif>
                    <summary class="flex cursor-pointer list-none items-center justify-between gap-4 p-5 focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary">
                        <div><span class="font-semibold">{{ $coalition->name }}</span><span class="ml-2 badge {{ $statusTone($coalition->status->value) }} badge-sm">{{ str($coalition->status->value)->headline() }}</span><p class="mt-1 text-xs nexus-text-muted">Roster revision {{ $coalition->roster_revision }} · coordinator {{ $coalition->coordinator_installation_id }}</p></div>
                        <x-icon name="o-chevron-down" class="size-5 shrink-0 transition group-open:rotate-180" aria-hidden="true" />
                    </summary>
                    <div class="border-t border-base-300 p-5">
                        @php
                            $localMembership = $coalition->memberships->firstWhere('installation_id', $identityId);
                            $capabilityMatrix = $coalition->capabilities
                                ->sortByDesc('revision')
                                ->unique(fn ($capability) => implode(':', [
                                    $capability->issuer_installation_id,
                                    $capability->peer_installation_id,
                                    $capability->direction->value,
                                ]));
                        @endphp
                        <div class="overflow-x-auto">
                            <table class="table table-sm">
                                <thead><tr><th>Installation</th><th>Role</th><th>Status</th><th>Roster</th><th></th></tr></thead>
                                <tbody>
                                    @foreach ($coalition->memberships->sortBy('installation_id') as $membership)
                                        <tr>
                                            <td class="break-all font-mono text-xs">{{ $membership->installation_id }}</td>
                                            <td>{{ ucfirst($membership->role->value) }}</td>
                                            <td><span class="badge {{ $statusTone($membership->status->value) }} badge-sm">{{ str($membership->status->value)->headline() }}</span></td>
                                            <td class="tabular-nums">{{ $membership->roster_revision }}</td>
                                            <td>
                                                @can('manage-coalitions')
                                                    @if ($coalition->coordinator_installation_id === $identityId && $membership->status->value === 'active' && $membership->role->value !== 'coordinator')
                                                        <form method="POST" action="{{ route('admin.federation.coalitions.memberships.remove', [$coalition, $membership]) }}" data-confirm="Remove this installation? Every source must revoke its own outstanding publications to it." data-confirm-title="Remove coalition member?" data-confirm-label="Remove member" data-confirm-tone="error">
                                                            @csrf
                                                            <button class="btn btn-xs btn-error btn-outline" type="submit">Remove</button>
                                                        </form>
                                                    @endif
                                                @endcan
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        <div class="mt-5 border-t border-base-300 pt-5">
                            <h3 class="font-semibold">Explicit capability matrix</h3>
                            <p class="mt-1 text-xs nexus-text-muted">Roles never imply access. The latest signed revision for each issuer, peer, and direction is shown.</p>
                            <div class="mt-3 overflow-x-auto rounded-md border border-base-300">
                                <table class="table table-sm">
                                    <thead><tr><th>Issuer</th><th>Peer</th><th>Direction</th><th>State</th><th>Revision</th><th>Expiry</th></tr></thead>
                                    <tbody>
                                        @forelse ($capabilityMatrix as $capability)
                                            <tr><td class="font-mono text-xs">{{ $capability->issuer_installation_id === $identityId ? 'This installation' : $capability->issuer_installation_id }}</td><td class="font-mono text-xs">{{ $capability->peer_installation_id === $identityId ? 'This installation' : $capability->peer_installation_id }}</td><td>{{ ucfirst($capability->direction->value) }}</td><td><span class="badge {{ $statusTone($capability->state->value) }} badge-sm">{{ str($capability->state->value)->headline() }}</span></td><td class="tabular-nums">{{ $capability->revision }}</td><td>{{ $capability->expires_at?->diffForHumans() ?: 'No expiry' }}</td></tr>
                                        @empty
                                            <tr><td colspan="6" class="py-6 text-center text-sm nexus-text-muted">No capability statements. Sharing is denied.</td></tr>
                                        @endforelse
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        @if ($coalition->proposals->filter(fn ($proposal) => in_array($proposal->status->value, ['pending', 'approved'], true))->isNotEmpty())
                            <div class="mt-5 border-t border-base-300 pt-5">
                                <h3 class="font-semibold">Roster proposals</h3>
                                <div class="mt-3 grid gap-3">
                                    @foreach ($coalition->proposals->filter(fn ($proposal) => in_array($proposal->status->value, ['pending', 'approved'], true)) as $proposal)
                                        <div class="flex flex-col gap-3 rounded-md bg-base-200/65 p-4 lg:flex-row lg:items-center lg:justify-between">
                                            <div><p class="font-semibold">{{ str($proposal->proposal_type)->replace('.', ' ')->headline() }}</p><p class="mt-1 break-all text-xs nexus-text-muted">Target {{ $proposal->target_installation_id ?: 'none' }} · base roster r{{ $proposal->base_roster_revision }} · {{ str($proposal->status->value)->headline() }}</p></div>
                                            <div class="flex flex-wrap gap-2">
                                                @if ($proposal->proposal_type === 'coordinator.transfer' && $proposal->target_installation_id === $identityId && $proposal->status->value === 'pending')
                                                    <form method="POST" action="{{ route('admin.federation.coalitions.transfers.accept', $proposal) }}" data-confirm="Accept coordinator responsibility for this coalition? The current coordinator must still give final approval." data-confirm-title="Accept coordinator transfer?" data-confirm-label="Accept transfer">@csrf<button class="btn btn-sm btn-primary" type="submit">Accept transfer</button></form>
                                                @endif
                                                @if ($coalition->coordinator_installation_id === $identityId)
                                                    @if ($proposal->proposal_type === 'coordinator.transfer' && $proposal->status->value === 'approved')
                                                        <form method="POST" action="{{ route('admin.federation.coalitions.transfers.approve', $proposal) }}" data-confirm="Give final approval to this accepted coordinator transfer?" data-confirm-title="Complete coordinator transfer?" data-confirm-label="Approve transfer">@csrf<button class="btn btn-sm btn-primary" type="submit">Final approval</button></form>
                                                    @elseif ($proposal->proposal_type !== 'coordinator.transfer' && $proposal->status->value === 'pending')
                                                        <form method="POST" action="{{ route('admin.federation.coalitions.proposals.approve', $proposal) }}">@csrf<button class="btn btn-sm btn-primary" type="submit">Approve</button></form>
                                                        <form method="POST" action="{{ route('admin.federation.coalitions.proposals.reject', $proposal) }}">@csrf<input type="hidden" name="reason_code" value="rejected_by_coordinator"><button class="btn btn-sm btn-error btn-outline" type="submit">Reject</button></form>
                                                    @endif
                                                @endif
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endif

                        @can('manage-coalitions')
                            @if ($coalition->status->value === 'active')
                                <div class="mt-5 grid gap-5 border-t border-base-300 pt-5 xl:grid-cols-2">
                                    @if ($coalition->coordinator_installation_id === $identityId)
                                        <form method="POST" action="{{ route('admin.federation.coalitions.invite', $coalition) }}" class="grid gap-3 sm:grid-cols-2">
                                            @csrf
                                            <label class="sm:col-span-2"><span class="label px-0">Invite active peer</span><select class="select w-full" name="link_id" required><option value="">Select installation</option>@foreach ($activeLinks as $link)<option value="{{ $link->id }}">{{ $link->remote_display_name ?: $link->remote_installation_id }}</option>@endforeach</select></label>
                                            <label><span class="label px-0">Role</span><select class="select w-full" name="role"><option value="member">Member</option><option value="observer">Observer</option><option value="admin">Admin</option></select></label>
                                            <button class="btn btn-outline self-end" type="submit">Send invitation</button>
                                        </form>
                                    @endif

                                    <form method="POST" action="{{ route('admin.federation.capabilities.store', $coalition) }}" class="grid gap-3 sm:grid-cols-2">
                                        @csrf
                                        <label class="sm:col-span-2"><span class="label px-0">Peer capability</span><select class="select w-full" name="link_id" required><option value="">Select linked member</option>@foreach ($activeLinks as $link)<option value="{{ $link->id }}">{{ $link->remote_display_name ?: $link->remote_installation_id }}</option>@endforeach</select></label>
                                        <label><span class="label px-0">Direction</span><select class="select w-full" name="direction"><option value="outbound">Outbound publishing</option><option value="inbound">Inbound acceptance</option></select></label>
                                        <label><span class="label px-0">State</span><select class="select w-full" name="state"><option value="active">Allow</option><option value="revoked">Revoke</option></select></label>
                                        <label><span class="label px-0">Optional expiry</span><input class="input w-full" type="datetime-local" name="expires_at"></label>
                                        <button class="btn btn-primary self-end" type="submit">Publish capability revision</button>
                                    </form>
                                </div>

                                @if ($localMembership?->status->value === 'active' && in_array($localMembership->role->value, ['coordinator', 'admin'], true))
                                    <form method="POST" action="{{ route('admin.federation.coalitions.proposals.store', $coalition) }}" class="mt-5 grid gap-3 border-t border-base-300 pt-5 sm:grid-cols-2 xl:grid-cols-4">
                                        @csrf
                                        <label><span class="label px-0">Roster proposal</span><select class="select w-full" name="proposal_type"><option value="member.role">Change role</option><option value="member.remove">Remove member</option>@if ($coalition->coordinator_installation_id === $identityId)<option value="coordinator.transfer">Transfer coordinator</option>@endif</select></label>
                                        <label><span class="label px-0">Target installation</span><select class="select w-full" name="target_installation_id" required><option value="">Select member</option>@foreach ($coalition->memberships->filter(fn ($member) => $member->status->value === 'active') as $member)<option value="{{ $member->installation_id }}">{{ $member->installation_id }} · {{ $member->role->value }}</option>@endforeach</select></label>
                                        <label><span class="label px-0">Requested role</span><select class="select w-full" name="requested_role"><option value="">Not applicable</option><option value="observer">Observer</option><option value="member">Member</option><option value="admin">Admin</option></select></label>
                                        <button class="btn btn-outline self-end" type="submit">Submit proposal</button>
                                    </form>
                                @endif

                                @if ($coalition->coordinator_installation_id === $identityId)
                                    <form method="POST" action="{{ route('admin.federation.coalitions.dissolve', $coalition) }}" class="mt-5 flex justify-end" data-confirm="Dissolve this coalition? Capabilities expire and source-owned publication revocations are queued." data-confirm-title="Dissolve coalition?" data-confirm-label="Dissolve" data-confirm-tone="error">
                                        @csrf
                                        <button class="btn btn-sm btn-error btn-outline" type="submit">Dissolve coalition</button>
                                    </form>
                                @endif
                            @endif
                        @endcan
                    </div>
                </details>
            @empty
                <div class="p-8 text-center"><p class="font-semibold">No coalition scope exists</p><p class="mt-1 text-sm nexus-text-muted">A coordinator creates a coalition, invites active links, then grants explicit per-peer capabilities.</p></div>
            @endforelse
        </div>
    </section>

    <section id="publish" class="nexus-panel scroll-mt-24" aria-labelledby="federation-publish-title">
        <div class="nexus-panel__header">
            <div><h2 id="federation-publish-title" class="nexus-section-title">Publish war-plan snapshots</h2><p class="mt-1 text-sm text-base-content/65">Preview locks the exact canonical bytes, recipients, expiry, byte size, and hash before delivery.</p></div>
            <span class="badge {{ config('federation.features.publishing') ? 'badge-success' : 'badge-warning' }}">Publishing {{ config('federation.features.publishing') ? 'enabled' : 'disabled' }}</span>
        </div>

        @if (auth()->user()->can('publish-federated-war-plans') && auth()->user()->can('manage-war-room'))
            <div class="nexus-panel__body border-b border-base-300">
                <form method="POST" action="{{ route('admin.federation.publications.preview') }}" class="grid gap-5" data-federation-publication-form>
                    @csrf
                    <div class="grid gap-4 lg:grid-cols-3">
                        <label><span class="label px-0">Source operation</span><select class="select w-full" name="operation_id" required data-federation-operation><option value="">Choose committed plan</option>@foreach ($eligibleOperations as $operation)<option value="{{ $operation->id }}">#{{ $operation->id }} · {{ $operation->name }}</option>@endforeach</select></label>
                        <label><span class="label px-0">Coalition</span><select class="select w-full" name="coalition_id" required><option value="">Choose coalition</option>@foreach ($activeCoalitions as $coalition)<option value="{{ $coalition->id }}">{{ $coalition->name }} · r{{ $coalition->roster_revision }}</option>@endforeach</select></label>
                        <label><span class="label px-0">Existing publication (update)</span><select class="select w-full" name="publication_id"><option value="">New publication</option>@foreach ($publications->whereIn('status', [\App\Domain\Federation\Enums\PublicationStatus::Published, \App\Domain\Federation\Enums\PublicationStatus::PartiallyRevoked]) as $publication)<option value="{{ $publication->id }}">{{ $publication->operation?->name }} · v{{ $publication->current_version }}</option>@endforeach</select></label>
                    </div>
                    <div class="grid gap-4 lg:grid-cols-3">
                        <label><span class="label px-0">Share title</span><input class="input w-full" name="title" maxlength="255" required></label>
                        <label><span class="label px-0">Wave label</span><input class="input w-full" name="wave_label" maxlength="100"></label>
                        <label><span class="label px-0">Expiry</span><input class="input w-full" type="datetime-local" name="expires_at" value="{{ now()->addDays(config('federation.publication_default_expiry_days', 7))->format('Y-m-d\TH:i') }}" required></label>
                    </div>
                    <label><span class="label px-0">Recipient instructions</span><textarea class="textarea min-h-24 w-full" name="recipient_instructions" maxlength="1000" placeholder="Optional share-specific context. Existing war reasons are never copied."></textarea></label>

                    <fieldset><legend class="font-semibold">Recipients</legend><p class="mt-1 text-xs nexus-text-muted">Each selected installation receives distinct ciphertext.</p><div class="mt-3 flex flex-wrap gap-3">@forelse ($activeLinks as $link)<label class="flex cursor-pointer items-center gap-2 rounded-md border border-base-300 px-3 py-2 text-sm"><input class="checkbox checkbox-sm" type="checkbox" name="recipient_link_ids[]" value="{{ $link->id }}">{{ $link->remote_display_name ?: $link->remote_installation_id }}</label>@empty<span class="text-sm nexus-text-muted">No active peer links.</span>@endforelse</div></fieldset>

                    <fieldset><legend class="font-semibold">Targets</legend><p class="mt-1 text-xs nexus-text-muted">Only open, non-hold objectives from the selected operation are eligible.</p><div class="mt-3 grid max-h-72 gap-2 overflow-y-auto rounded-md border border-base-300 p-3">@forelse ($eligibleOperations as $operation)@foreach ($operation->objectives as $objective)<label class="flex cursor-pointer items-center justify-between gap-4 text-sm" data-federation-objective data-operation-id="{{ $operation->id }}" hidden><span class="flex items-center gap-2"><input class="checkbox checkbox-sm" type="checkbox" name="objective_ids[]" value="{{ $objective->id }}" disabled>Nation #{{ $objective->target_nation_id }}</span><span class="badge badge-ghost badge-sm">{{ $objective->priority_tier->value }}</span></label>@endforeach @empty<p class="text-sm nexus-text-muted">No plan objectives are available.</p>@endforelse</div></fieldset>

                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"><p class="text-sm nexus-text-muted">Previewing does not send anything. Publishing revalidates the exact bytes and generation.</p><button class="btn btn-primary" type="submit" @disabled(! config('federation.features.publishing'))>Review exact payload</button></div>
                </form>
            </div>
        @endif

        <div class="overflow-x-auto">
            <table class="table">
                <thead><tr><th>Publication</th><th>Version</th><th>Recipients</th><th>Delivery status</th><th>Expiry</th><th>Actions</th></tr></thead>
                <tbody>
                    @forelse ($publications as $publication)
                        @php $latestVersion = $publication->versions->sortByDesc('version')->first(); @endphp
                        <tr>
                            <td><p class="font-semibold">{{ $publication->operation?->name }}</p><p class="mt-1 break-all text-xs nexus-text-muted">{{ $publication->id }} · {{ $publication->coalition?->name }}</p></td>
                            <td><span class="badge {{ $statusTone($publication->status->value) }} badge-sm">{{ str($publication->status->value)->headline() }}</span><p class="mt-1 text-xs">v{{ $publication->current_version }} / r{{ $publication->current_revision }}</p></td>
                            <td class="tabular-nums">{{ $latestVersion?->deliveries->count() ?? 0 }}</td>
                            <td><div class="flex max-w-72 flex-wrap gap-1">@foreach ($latestVersion?->deliveries ?? [] as $delivery)<span class="badge {{ $statusTone($delivery->state->value) }} badge-xs">{{ $delivery->link?->remote_display_name ?: str($delivery->state->value)->headline() }}</span>@endforeach</div></td>
                            <td>{{ $publication->expires_at?->diffForHumans() }}</td>
                            <td>
                                @if (auth()->user()->can('publish-federated-war-plans') && auth()->user()->can('manage-war-room'))
                                    @if (! in_array($publication->status->value, ['revoked', 'expired'], true))
                                        <details class="min-w-56">
                                            <summary class="btn btn-sm btn-outline">Revocation controls</summary>
                                            <div class="mt-2 grid gap-2 rounded-md border border-base-300 p-2">
                                                @foreach (($latestVersion?->deliveries ?? collect())->filter(fn ($delivery) => $delivery->state->value !== 'revoked') as $delivery)
                                                    <form method="POST" action="{{ route('admin.federation.publications.revoke-recipient', $publication) }}" data-confirm="Revoke this recipient's access? Other recipients keep their current access." data-confirm-title="Revoke recipient access?" data-confirm-label="Revoke access" data-confirm-tone="error">
                                                        @csrf
                                                        <input type="hidden" name="recipient_installation_id" value="{{ $delivery->recipient_installation_id }}">
                                                        <input type="hidden" name="reason_code" value="administrator_revoked">
                                                        <button class="btn btn-xs btn-outline w-full justify-start" type="submit">{{ $delivery->link?->remote_display_name ?: $delivery->recipient_installation_id }}</button>
                                                    </form>
                                                @endforeach
                                                <form method="POST" action="{{ route('admin.federation.publications.revoke', $publication) }}" data-confirm="Revoke this publication for every recipient? Decrypted remote snapshots are purged when the tombstone is processed." data-confirm-title="Revoke publication?" data-confirm-label="Revoke all" data-confirm-tone="error">
                                                    @csrf
                                                    <input type="hidden" name="reason_code" value="administrator_revoked">
                                                    <button class="btn btn-xs btn-error btn-outline w-full" type="submit">Revoke all recipients</button>
                                                </form>
                                            </div>
                                        </details>
                                    @endif
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="py-10 text-center"><p class="font-semibold">No war plan has been shared</p><p class="mt-1 text-sm nexus-text-muted">An officer must pass the exact preview gate before any envelope is queued.</p></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    @can('review-federated-war-plans')
    <section id="received" class="nexus-panel scroll-mt-24" aria-labelledby="federation-received-title">
        <div class="nexus-panel__header"><div><h2 id="federation-received-title" class="nexus-section-title">Received plans</h2><p class="mt-1 text-sm text-base-content/65">Review exact shared fields and version changes. Acceptance queues an idempotent local draft import.</p></div><span class="badge badge-outline">{{ $receivedReviews->count() }} versions</span></div>
        <div class="divide-y divide-base-300">
            @forelse ($receivedReviews as $row)
                @php
                    $version = $row['version'];
                    $resource = $row['resource'];
                    $snapshot = $row['snapshot'];
                @endphp
                <details @if ($version->disposition->value === 'pending') open @endif>
                    <summary class="flex cursor-pointer list-none flex-col gap-2 p-5 focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary sm:flex-row sm:items-center sm:justify-between">
                        <div><span class="font-semibold">{{ $snapshot?->title ?: 'Redacted federation snapshot' }}</span><p class="mt-1 text-xs nexus-text-muted">{{ $resource->link?->remote_display_name ?: $resource->source_installation_id }} · v{{ $version->version }} · r{{ $version->revision }}</p></div>
                        <div class="flex flex-wrap gap-2"><span class="badge {{ $statusTone($version->disposition->value) }} badge-sm">{{ str($version->disposition->value)->headline() }}</span><span class="badge {{ $statusTone($version->import_state->value) }} badge-sm">{{ str($version->import_state->value)->headline() }}</span></div>
                    </summary>
                    <div class="border-t border-base-300 p-5">
                        <dl class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                            <div><dt class="text-xs font-semibold uppercase tracking-wide text-base-content/55">Source fingerprint</dt><dd class="mt-1 break-all font-mono text-xs">{{ $resource->link?->activePeerKey?->signing_fingerprint ?: 'Unavailable' }}</dd></div>
                            <div><dt class="text-xs font-semibold uppercase tracking-wide text-base-content/55">Coalition / roster</dt><dd class="mt-1 text-sm">{{ $resource->coalition_id }} · r{{ $version->roster_revision }}</dd></div>
                            <div><dt class="text-xs font-semibold uppercase tracking-wide text-base-content/55">Freshness</dt><dd class="mt-1 text-sm">Received {{ $version->created_at?->diffForHumans() }}</dd></div>
                            <div><dt class="text-xs font-semibold uppercase tracking-wide text-base-content/55">Expiry</dt><dd class="mt-1 text-sm">{{ $version->expires_at?->diffForHumans() }}</dd></div>
                        </dl>

                        @if ($snapshot)
                            <div class="mt-5 grid gap-5 xl:grid-cols-[minmax(0,1.4fr)_minmax(18rem,0.6fr)]">
                                <div class="overflow-x-auto rounded-md border border-base-300">
                                    <table class="table table-sm">
                                        <thead><tr><th>Target</th><th>Priority</th><th>War type</th><th>Team</th><th>Deadline</th></tr></thead>
                                        <tbody>@foreach ($snapshot->targets as $target)<tr><td><p class="font-semibold">{{ $target->targetNationName ?: 'Nation #'.$target->targetNationId }}</p><p class="text-xs nexus-text-muted">#{{ $target->targetNationId }} · {{ $target->targetAllianceName ?: 'No alliance hint' }}</p></td><td>{{ $target->priorityTier->value }}</td><td>{{ $target->warType }}</td><td>{{ $target->minimumTeamSize }}–{{ $target->desiredTeamSize }}</td><td>{{ $target->deadlineAt?->toDayDateTimeString() ?: 'None' }}</td></tr>@endforeach</tbody>
                                    </table>
                                </div>
                                <div class="space-y-4">
                                    <div><h3 class="font-semibold">Version changes</h3><ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-base-content/70"><li>Fields: {{ implode(', ', $row['diff']['changed_fields']) ?: 'none' }}</li><li>Targets added: {{ implode(', ', $row['diff']['targets_added']) ?: 'none' }}</li><li>Targets removed: {{ implode(', ', $row['diff']['targets_removed']) ?: 'none' }}</li><li>Targets changed: {{ implode(', ', $row['diff']['targets_changed']) ?: 'none' }}</li></ul></div>
                                    <div><h3 class="font-semibold">Recipient instructions</h3><p class="mt-2 whitespace-pre-wrap text-sm leading-6 text-base-content/70">{{ $snapshot->recipientInstructions ?: 'No instructions were included.' }}</p></div>
                                    <p class="break-all text-xs nexus-text-muted">Payload SHA-256 {{ $version->payload_hash }}</p>
                                </div>
                            </div>
                        @else
                            <div class="alert alert-warning mt-5"><p class="text-sm">The decrypted payload has been purged. Only provenance, hash, timestamps, disposition, and import state remain.</p></div>
                        @endif

                        <div class="mt-5 flex flex-wrap gap-3 border-t border-base-300 pt-5">
                            @if ($version->disposition->value === 'pending' && $snapshot)
                                @can('review-federated-war-plans')
                                    <form method="POST" action="{{ route('admin.federation.received.reject', $version) }}" data-confirm="Reject this version? The decrypted payload is purged after the signed disposition is queued." data-confirm-title="Reject received plan?" data-confirm-label="Reject" data-confirm-tone="error">@csrf<button class="btn btn-outline" type="submit">Reject</button></form>
                                @endcan
                                @if (auth()->user()->can('import-federated-war-plans') && auth()->user()->can('manage-war-room'))
                                    <form method="POST" action="{{ route('admin.federation.received.accept', $version) }}" data-confirm="Accept this version and queue a local draft import using configured friendly alliances and exact target nation IDs?" data-confirm-title="Accept and import?" data-confirm-label="Accept plan">@csrf<button class="btn btn-primary" type="submit">Accept and import draft</button></form>
                                @endif
                            @endif
                            @if (in_array($version->import_state->value, ['blocked_missing_targets', 'failed'], true) && auth()->user()->can('import-federated-war-plans') && auth()->user()->can('manage-war-room'))
                                <form method="POST" action="{{ route('admin.federation.received.retry-import', $version) }}">@csrf<button class="btn btn-outline" type="submit">Retry import</button></form>
                                @if ($version->missing_target_ids)<span class="text-sm text-warning">Missing nation IDs: {{ implode(', ', $version->missing_target_ids) }}</span>@endif
                            @endif
                        </div>

                        @if ($version->importedOperation?->federation_action_required && auth()->user()->can('import-federated-war-plans') && auth()->user()->can('manage-war-room'))
                            <div class="alert alert-error mt-5 items-start"><x-icon name="o-hand-raised" class="mt-0.5 size-5 shrink-0" aria-hidden="true" /><div class="w-full"><p class="font-semibold">Local operation #{{ $version->importedOperation->id }} is under a hard hold</p><p class="mt-1 text-sm">{{ str($version->importedOperation->federation_hold_reason)->headline() }}. Remote payload recovery is not possible after purge.</p><div class="mt-4 grid gap-4 lg:grid-cols-2"><form method="POST" action="{{ route('admin.federation.operations.detach', $version->importedOperation) }}" data-confirm="Continue independently? This permanently detaches the operation from remote updates." data-confirm-title="Detach local operation?" data-confirm-label="Continue independently">@csrf<label><span class="label px-0">Reason</span><textarea class="textarea w-full" name="reason" minlength="10" maxlength="1000" required></textarea></label><button class="btn btn-outline mt-2" type="submit">Continue independently</button></form><form method="POST" action="{{ route('admin.federation.operations.retire', $version->importedOperation) }}" data-confirm="Retire this operation through the normal completion and archive lifecycle? Engaged wars may keep the hold in place." data-confirm-title="Retire held operation?" data-confirm-label="Retire operation" data-confirm-tone="error">@csrf<label><span class="label px-0">Reason</span><textarea class="textarea w-full" name="reason" minlength="10" maxlength="1000" required></textarea></label><button class="btn btn-error btn-outline mt-2" type="submit">Retire local operation</button></form></div></div></div>
                        @endif
                    </div>
                </details>
            @empty
                <div class="p-8 text-center"><p class="font-semibold">Federation inbox is empty</p><p class="mt-1 text-sm nexus-text-muted">Validated war-plan snapshots will appear here for authorized review.</p></div>
            @endforelse
        </div>
    </section>
    @endcan

    <section id="diagnostics" class="nexus-panel scroll-mt-24" aria-labelledby="federation-diagnostics-title">
        <div class="nexus-panel__header"><div><h2 id="federation-diagnostics-title" class="nexus-section-title">Payload-free diagnostics</h2><p class="mt-1 text-sm text-base-content/65">Queue and compatibility signals never include keys, ciphertext, titles, targets, or instructions.</p></div></div>
        <div class="nexus-panel__body">
            @if ($diagnostics)
                <dl class="grid gap-5 sm:grid-cols-2 xl:grid-cols-4">
                    <div><dt class="text-sm nexus-text-muted">Pending outbox</dt><dd class="mt-1 text-2xl font-semibold tabular-nums">{{ number_format($diagnostics['pending_outbox']) }}</dd></div>
                    <div><dt class="text-sm nexus-text-muted">Oldest outbox</dt><dd class="mt-1 font-semibold">{{ $diagnostics['oldest_outbox_at'] ? \Carbon\CarbonImmutable::parse($diagnostics['oldest_outbox_at'])->diffForHumans() : 'None' }}</dd></div>
                    <div><dt class="text-sm nexus-text-muted">Quarantined inbox</dt><dd class="mt-1 text-2xl font-semibold tabular-nums">{{ number_format($diagnostics['quarantined_inbox']) }}</dd></div>
                    <div><dt class="text-sm nexus-text-muted">Oldest unprocessed inbox</dt><dd class="mt-1 font-semibold">{{ $diagnostics['oldest_inbox_at'] ? \Carbon\CarbonImmutable::parse($diagnostics['oldest_inbox_at'])->diffForHumans() : 'None' }}</dd></div>
                </dl>
            @else
                <p class="text-sm nexus-text-muted">The <code>view-federation-diagnostics</code> permission is required for operational health details.</p>
            @endif
        </div>
    </section>

    @push('scripts')
        <script>
            document.addEventListener('DOMContentLoaded', () => {
                const operation = document.querySelector('[data-federation-operation]');
                const objectives = Array.from(document.querySelectorAll('[data-federation-objective]'));

                const updateObjectives = () => {
                    objectives.forEach((row) => {
                        const visible = operation?.value && row.dataset.operationId === operation.value;
                        row.hidden = !visible;
                        const checkbox = row.querySelector('input[type="checkbox"]');
                        checkbox.disabled = !visible;
                        if (!visible) checkbox.checked = false;
                    });
                };

                operation?.addEventListener('change', updateObjectives);
                updateObjectives();
            });
        </script>
    @endpush
@endsection
