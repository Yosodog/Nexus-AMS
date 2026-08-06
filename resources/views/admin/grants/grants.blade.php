@php
    use App\Services\PWHelperService;
    use Illuminate\Support\Js;
    use Illuminate\Support\Str;

    $oldGrantPayload = null;

    if (old('name') !== null || old('description') !== null || old('validation_rules_json') !== null) {
        $oldGrantPayload = [
            'id' => old('id'),
            'name' => old('name', ''),
            'description' => old('description', ''),
            'money' => old('money', 0),
            'is_enabled' => filter_var(old('is_enabled', '1'), FILTER_VALIDATE_BOOLEAN),
            'is_one_time' => filter_var(old('is_one_time', '0'), FILTER_VALIDATE_BOOLEAN),
            'validation_rules' => old('validation_rules') ?: (old('validation_rules_json') ? json_decode((string) old('validation_rules_json'), true) : null),
        ];

        foreach (PWHelperService::resources(false) as $resource) {
            $oldGrantPayload[$resource] = old($resource, 0);
        }
    }

    $canManageGrants = auth()->user()?->can('manage-grants') ?? false;
    $canBypassSelfRestrictions = auth()->user()?->can('bypass-self-restrictions') ?? false;
@endphp
@extends('layouts.admin')

@section('title', 'Grant Programs')

@section('content')
    <header class="nexus-page-header">
        <div class="nexus-page-header__copy">
            <h1 class="nexus-page-title">Grant programs</h1>
            <p class="nexus-page-summary">Review requests against their exact payout, then maintain reusable grant definitions and eligibility rules.</p>
        </div>
        <div class="nexus-page-header__actions">
            <span class="nexus-status {{ $pendingCount > 0 ? 'nexus-status--warning' : 'nexus-status--success' }}">
                {{ number_format($pendingCount) }} pending
            </span>
            @can('manage-grants')
                <button
                    type="button"
                    onclick="clearGrantForm(); document.getElementById('grantModal').showModal()"
                    class="btn btn-primary btn-sm"
                >
                    <x-icon name="o-plus" class="size-4" aria-hidden="true" />
                    Create grant program
                </button>
            @endcan
        </div>
    </header>

    @unless($grantApprovalsEnabled)
        <div class="alert alert-warning" role="status">
            <x-icon name="o-pause-circle" class="size-5" aria-hidden="true" />
            <div>
                <p class="font-semibold">Grant approvals are paused</p>
                <p class="text-sm">Pending requests are preserved. Staff can still deny a request, but deposits cannot be approved until the global control is enabled.</p>
            </div>
        </div>
    @endunless

    <section class="nexus-panel nexus-panel--raised" aria-labelledby="pending-grants-title">
        <div class="nexus-panel__header">
            <div>
                <h2 id="pending-grants-title" class="nexus-section-title">Pending grant requests</h2>
                <p class="nexus-body-muted mt-1">Approval deposits the displayed payout into the selected account immediately.</p>
            </div>
            @unless($canManageGrants)
                <span class="nexus-status nexus-status--neutral">View only</span>
            @endunless
        </div>

        @forelse ($pendingRequests as $request)
            @php
                $isOwnRequest = ! $canBypassSelfRestrictions
                    && auth()->user()?->nation_id !== null
                    && (int) auth()->user()->nation_id === (int) $request->nation_id;
                $payoutResources = collect(PWHelperService::resources())
                    ->mapWithKeys(fn ($resource) => [$resource => (float) ($request->grant?->$resource ?? 0)])
                    ->filter(fn ($amount) => $amount > 0);
            @endphp
            <article class="grid gap-4 border-b border-base-300 px-5 py-4 last:border-b-0 xl:grid-cols-[minmax(0,1.05fr)_minmax(16rem,0.95fr)_auto] xl:items-center">
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <h3 class="font-semibold">{{ $request->grant?->name ?? 'Unknown grant' }}</h3>
                        <span class="nexus-status nexus-status--warning">Pending</span>
                    </div>
                    @if ($request->nation)
                        <a href="https://politicsandwar.com/nation/id={{ $request->nation->id }}" target="_blank" rel="noopener" class="mt-1 block w-fit font-medium text-primary hover:underline">
                            {{ $request->nation->leader_name ?? ('Nation #'.$request->nation->id) }}
                        </a>
                        <p class="text-sm nexus-text-muted">{{ $request->nation->nation_name ?? 'Unknown nation name' }} · Nation #{{ $request->nation_id }}</p>
                    @else
                        <p class="mt-1 text-sm nexus-text-muted">Unknown nation · Nation #{{ $request->nation_id }}</p>
                    @endif
                    <p class="mt-1 text-xs nexus-text-muted">
                        Requested <time datetime="{{ $request->created_at->toIso8601String() }}" class="tooltip tooltip-bottom cursor-help" data-tip="{{ $request->created_at->toDayDateTimeString() }}" tabindex="0" aria-label="Requested {{ $request->created_at->diffForHumans() }}, {{ $request->created_at->toDayDateTimeString() }}">{{ $request->created_at->diffForHumans() }}</time>
                        · Account {{ $request->account?->name ?? '#'.$request->account_id }}
                    </p>
                </div>

                <div>
                    <p class="text-xs font-semibold uppercase tracking-wide nexus-text-muted">Payout on approval</p>
                    <div class="mt-2 flex flex-wrap gap-2">
                        @forelse($payoutResources as $resource => $amount)
                            <span class="nexus-status nexus-status--neutral">
                                {{ Str::headline($resource) }} {{ $resource === 'money' ? '$'.number_format($amount, 0) : number_format($amount, 0) }}
                            </span>
                        @empty
                            <span class="text-sm nexus-text-muted">No payout configured</span>
                        @endforelse
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-2 xl:justify-end">
                    @if($canManageGrants && ! $isOwnRequest)
                        @if($grantApprovalsEnabled)
                            <form action="{{ route('admin.grants.approve', $request) }}" method="POST" data-confirm="Approve this request and deposit the displayed payout into the selected account?" data-confirm-title="Approve grant request?" data-confirm-label="Approve and deposit">
                                @csrf
                                <button type="submit" class="btn btn-success btn-sm">Approve and deposit</button>
                            </form>
                        @else
                            <span class="nexus-status nexus-status--warning">Approval paused</span>
                        @endif
                        <form action="{{ route('admin.grants.deny', $request) }}" method="POST" data-confirm="Deny this grant request? The applicant will be notified and no funds will be deposited." data-confirm-title="Deny grant request?" data-confirm-label="Deny request" data-confirm-tone="error">
                            @csrf
                            <button type="submit" class="btn btn-error btn-outline btn-sm">Deny request</button>
                        </form>
                    @elseif($isOwnRequest)
                        <span class="text-sm">
                            <span class="nexus-status nexus-status--error">Self-decision blocked</span>
                            <span class="mt-1 block nexus-text-muted">Another reviewer must decide.</span>
                        </span>
                    @else
                        <span class="nexus-status nexus-status--neutral">Decision unavailable</span>
                    @endif
                </div>
            </article>
        @empty
            <div class="nexus-empty-state">
                <x-icon name="o-check-circle" class="size-8 text-success" aria-hidden="true" />
                <div>
                    <h3 class="font-semibold">Grant queue is clear</h3>
                    <p class="mt-1 text-sm nexus-text-muted">There are no pending custom grant requests.</p>
                </div>
            </div>
        @endforelse
    </section>

    <dl class="nexus-metrics">
        <div class="nexus-metric">
            <dt class="nexus-stat-label">Pending</dt>
            <dd class="nexus-stat-value">{{ number_format($pendingCount) }}</dd>
            <p class="nexus-stat-helper">Awaiting a decision</p>
        </div>
        <div class="nexus-metric">
            <dt class="nexus-stat-label">Approved</dt>
            <dd class="nexus-stat-value">{{ number_format($totalApproved) }}</dd>
            <p class="nexus-stat-helper">All-time decisions</p>
        </div>
        <div class="nexus-metric">
            <dt class="nexus-stat-label">Denied</dt>
            <dd class="nexus-stat-value">{{ number_format($totalDenied) }}</dd>
            <p class="nexus-stat-helper">All-time decisions</p>
        </div>
        <div class="nexus-metric">
            <dt class="nexus-stat-label">Funds distributed</dt>
            <dd class="nexus-stat-value">${{ number_format($totalFundsDistributed) }}</dd>
            <p class="nexus-stat-helper">Approved money payouts</p>
        </div>
    </dl>

    @can('manage-grants')
        @can('manage-manual-disbursements')
        <details id="manual-grant-disbursement" class="nexus-panel" @if(old('grant_id') || old('nation_id') || old('account_id')) open @endif>
            <summary class="flex cursor-pointer list-none items-center justify-between gap-4 px-5 py-4 marker:hidden">
                <span>
                    <span class="block font-semibold">Manual grant disbursement</span>
                    <span class="mt-0.5 block text-sm nexus-text-muted">Immediately sends a grant and bypasses one-time and pending-request checks.</span>
                </span>
                <span class="flex items-center gap-2">
                    <span class="nexus-status {{ $grantApprovalsEnabled ? 'nexus-status--warning' : 'nexus-status--neutral' }}">
                        {{ $grantApprovalsEnabled ? 'Elevated action' : 'Paused' }}
                    </span>
                    <x-icon name="o-chevron-down" class="size-4 nexus-text-muted" aria-hidden="true" />
                </span>
            </summary>
            @if($grantApprovalsEnabled)
                <form method="POST" action="{{ route('admin.manual-disbursements.grants') }}" class="border-t border-base-300 p-5" data-confirm="Send this grant immediately? This bypasses the normal application and one-time checks." data-confirm-title="Send manual grant?" data-confirm-label="Send grant" data-confirm-tone="error">
                    @csrf
                    <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', (string) \Illuminate\Support\Str::uuid()) }}">
                    <div class="grid gap-4 md:grid-cols-3">
                        <label class="block">
                            <span class="label font-semibold text-sm">Grant</span>
                            <select name="grant_id" class="select w-full" required>
                                <option value="">Select a grant</option>
                                @foreach($grants as $grant)
                                    <option value="{{ $grant->id }}" @selected(old('grant_id') == $grant->id)>
                                        {{ $grant->name }} ({{ $grant->is_one_time ? 'one-time' : 'repeatable' }})
                                    </option>
                                @endforeach
                            </select>
                        </label>
                        <x-input label="Nation ID" type="number" name="nation_id" required min="1" :value="old('nation_id')" />
                        <x-input label="Account ID" type="number" name="account_id" required min="1" :value="old('account_id')" hint="Must belong to the nation above." />
                    </div>
                    <div class="nexus-form-actions mt-5">
                        <button type="submit" class="btn btn-primary">Send grant immediately</button>
                    </div>
                </form>
            @else
                <div class="border-t border-base-300 p-5 text-sm text-base-content/65">
                    Manual disbursements are unavailable while grant approvals are paused.
                </div>
            @endif
        </details>
        @endcan
    @endcan

    <section class="nexus-panel" aria-labelledby="grant-programs-title">
        <div class="nexus-panel__header">
            <div>
                <h2 id="grant-programs-title" class="nexus-section-title">Grant definitions</h2>
                <p class="nexus-body-muted mt-1">Payouts and eligibility rules used by member applications.</p>
            </div>
            <span class="text-sm tabular-nums nexus-text-muted">{{ number_format($grants->count()) }} programs</span>
        </div>
        <div class="overflow-x-auto">
            <table class="nexus-table" data-sortable="false">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Status</th>
                        <th>One-Time</th>
                        <th>Requirements</th>
                        <th>Resources</th>
                        <th class="text-right" data-sortable="false">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($grants as $grant)
                        <tr>
                            <td>
                                <div class="font-semibold">{{ $grant->name }}</div>
                                <div class="text-sm nexus-text-muted">{{ Str::limit($grant->description, 72) }}</div>
                            </td>
                            <td>
                                <span class="nexus-status {{ $grant->is_enabled ? 'nexus-status--success' : 'nexus-status--neutral' }}">
                                    {{ $grant->is_enabled ? 'Enabled' : 'Disabled' }}
                                </span>
                            </td>
                            <td>{{ $grant->is_one_time ? 'Yes' : 'No' }}</td>
                            <td>
                                @if (!empty($grant->requirement_summary))
                                    <div class="flex flex-wrap gap-1">
                                        @foreach (array_slice($grant->requirement_summary, 0, 3) as $summary)
                                            <x-badge :value="$summary" class="badge-ghost badge-sm" />
                                        @endforeach
                                        @if (count($grant->requirement_summary) > 3)
                                            <x-badge  value="+{{ count($grant->requirement_summary) - 3 }} more" class="badge-neutral badge-sm" />
                                        @endif
                                    </div>
                                @else
                                    <span class="nexus-text-muted text-sm">No custom requirements</span>
                                @endif
                            </td>
                            <td>
                                <button
                                    type="button"
                                    class="btn btn-xs btn-ghost btn-outline"
                                    aria-controls="grant-resources-{{ $grant->id }}"
                                    aria-expanded="false"
                                    onclick="toggleGrantResources({{ $grant->id }}, this)"
                                >
                                    <x-icon name="o-eye" class="size-4" />
                                    <span>Resources</span>
                                </button>
                            </td>
                            <td class="text-right">
                                @can('manage-grants')
                                    <button
                                        type="button"
                                        class="btn btn-primary btn-sm"
                                        onclick="editGrant({{ Js::from($grant) }}); document.getElementById('grantModal').showModal()"
                                    >
                                        <x-icon name="o-pencil" class="size-4" />
                                        <span>Edit</span>
                                    </button>
                                @endcan
                            </td>
                        </tr>
                        <tr id="grant-resources-{{ $grant->id }}" class="hidden bg-base-200/40">
                            <td colspan="6">
                                <div class="flex flex-wrap gap-2 py-1">
                                    @foreach (PWHelperService::resources() as $resource)
                                        @if ((int) $grant->$resource > 0)
                                            <x-badge class="badge-outline badge-sm">
                                                <strong>{{ ucfirst($resource) }}:</strong>&nbsp;{{ number_format($grant->$resource) }}
                                            </x-badge>
                                        @endif
                                    @endforeach
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="py-8 text-center nexus-text-muted">No grant definitions have been created.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    @can('manage-grants')
    <dialog id="grantModal" class="modal modal-bottom sm:modal-middle" aria-labelledby="grant-modal-title">
        <div class="modal-box w-11/12 max-w-5xl max-h-[90vh] overflow-y-auto">
            <div class="flex items-start justify-between mb-4">
                <div>
                    <h3 id="grant-modal-title" class="font-bold text-lg">Manage grant program</h3>
                    <p class="nexus-text-muted text-sm">Create flexible grant requirements with nested logic, live summaries, and safe server-side enforcement.</p>
                </div>
                <button type="button" onclick="document.getElementById('grantModal').close()" class="btn btn-sm btn-ghost">Close</button>
            </div>

            <form id="grantForm" method="POST">
                @csrf
                <input type="hidden" name="id" id="grant_id">

                <div class="grid grid-cols-1 lg:grid-cols-5 gap-4">
                    {{-- Left: Grant Basics --}}
                    <div class="lg:col-span-2 bg-base-200 rounded-box p-4 space-y-4">
                        <div class="font-semibold">Grant basics</div>
                        <x-input label="Grant Name" name="name" id="grant_name" required />
                        <x-textarea label="Description" name="description" id="grant_description" rows="3" />
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="label text-sm font-semibold">Status</label>
                                <select class="select w-full" name="is_enabled" id="is_enabled">
                                    <option value="1">Enabled</option>
                                    <option value="0">Disabled</option>
                                </select>
                            </div>
                            <div>
                                <label class="label text-sm font-semibold">Grant Type</label>
                                <label class="flex items-center gap-2 cursor-pointer mt-2">
                                    <input type="checkbox" class="toggle toggle-primary" id="is_one_time" name="is_one_time">
                                    <span class="text-sm">One-time</span>
                                </label>
                            </div>
                        </div>

                        <div class="divider"></div>

                        <div class="font-semibold">Disbursement</div>
                        <x-input label="Money" type="number" name="money" id="grant_money" value="0" min="0" />
                        <div class="grid grid-cols-2 gap-3">
                            @foreach (PWHelperService::resources(false) as $resource)
                                <x-input :label="ucfirst($resource)" type="number" name="{{ $resource }}"
                                         id="grant_{{ $resource }}" value="0" min="0" />
                            @endforeach
                        </div>
                    </div>

                    {{-- Right: Eligibility Builder --}}
                    <div class="lg:col-span-3">
                        @include('admin.grants.partials.requirement-builder', [
                            'inputName' => 'validation_rules_json',
                            'builderConfig' => $grantRequirementBuilderConfig,
                            'showValidationErrors' => $oldGrantPayload && $errors->any(),
                            'emptySummaryHint' => 'Applications will only enforce the standard alliance and pending checks until you add rules.',
                        ])
                    </div>
                </div>

                <div class="modal-action">
                    <x-button label="Save Grant" type="submit" icon="o-check" class="btn-primary" />
                    <x-button label="Cancel" type="button" onclick="document.getElementById('grantModal').close()" class="btn-ghost" />
                </div>
            </form>
        </div>
        <form method="dialog" class="modal-backdrop"><button>close</button></form>
    </dialog>
    @endcan
@endsection

@push('scripts')
    <script>
        function getGrantRequirementBuilderRoot() {
            return document.querySelector('#grantForm [data-grant-requirement-builder]');
        }

        function setGrantRequirementBuilderValue(value) {
            const builderRoot = getGrantRequirementBuilderRoot();

            if (!builderRoot) {
                return;
            }

            if (builderRoot.grantRequirementBuilder) {
                builderRoot.grantRequirementBuilder.setValue(value);
                return;
            }

            builderRoot.dataset.grantRequirementInitialValue = typeof value === 'string'
                ? value
                : JSON.stringify(value ?? null);
        }

        function populateForm(grant) {
            document.getElementById('grant_id').value = grant?.id || '';
            document.querySelector('#grantForm [name="name"]').value = grant?.name || '';
            document.querySelector('#grantForm [name="description"]').value = grant?.description || '';
            document.querySelector('#grantForm [name="is_enabled"]').value = grant?.is_enabled ? '1' : '0';
            document.querySelector('#grantForm [name="is_one_time"]').checked = !!grant?.is_one_time;
            document.querySelector('#grantForm [name="money"]').value = grant?.money || 0;
            @foreach (PWHelperService::resources(false) as $resource)
                document.querySelector('#grantForm [name="{{ $resource }}"]').value = grant?.{{ $resource }} || 0;
            @endforeach
            setGrantRequirementBuilderValue(grant?.validation_rules || null);
        }

        function editGrant(grant) {
            document.getElementById('grantForm').action = `/admin/grants/${grant.id}/update`;
            populateForm(grant);
        }

        function toggleGrantResources(grantId, trigger) {
            const row = document.getElementById(`grant-resources-${grantId}`);

            if (!row) {
                return;
            }

            const isHidden = row.classList.toggle('hidden');

            if (trigger) {
                trigger.setAttribute('aria-expanded', isHidden ? 'false' : 'true');
            }
        }

        function clearGrantForm() {
            document.getElementById('grantForm').action = '/admin/grants/create';
            populateForm({
                is_enabled: true,
                is_one_time: false,
                money: 0,
                validation_rules: null,
                @foreach (PWHelperService::resources(false) as $resource)
                    {{ $resource }}: 0,
                @endforeach
            });
        }

        function initRegularGrantAdminPage() {
            const grantForm = document.getElementById('grantForm');

            if (!grantForm || grantForm.dataset.pageInitialized === 'true') {
                return;
            }

            grantForm.dataset.pageInitialized = 'true';

            @if ($oldGrantPayload)
                grantForm.action = "{{ $oldGrantPayload['id'] ? url('/admin/grants/'.$oldGrantPayload['id'].'/update') : url('/admin/grants/create') }}";
                populateForm(@json($oldGrantPayload));
                document.getElementById('grantModal')?.showModal();
            @else
                clearGrantForm();
            @endif
        }

        window.initRegularGrantAdminPage = initRegularGrantAdminPage;

        if (document.documentElement.dataset.regularGrantLifecycleBound !== 'true') {
            document.documentElement.dataset.regularGrantLifecycleBound = 'true';
            document.addEventListener('codex:page-ready', () => window.initRegularGrantAdminPage?.());
        }

        initRegularGrantAdminPage();
    </script>
@endpush
