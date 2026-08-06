@php
    $defaultReminderMessage = 'You are eligible for a city grant to help cover the cost of your next city. '
        .'If you are planning to expand soon, please submit an application so we can review it promptly.';
    $oldCityGrantPayload = $oldCityGrantPayload ?? null;

    if ($oldCityGrantPayload === null && old('requirements_json') !== null) {
        $oldCityGrantPayload = [
            'id' => old('id'),
            'city_number' => old('city_number', ''),
            'grant_amount' => old('grant_amount', 100),
            'enabled' => filter_var(old('enabled', '1'), FILTER_VALIDATE_BOOLEAN),
            'description' => old('description', ''),
            'requirements' => old('requirements') ?: (old('requirements_json') ? json_decode((string) old('requirements_json'), true) : null),
        ];
    }
    $canManageCityGrants = auth()->user()?->can('manage-city-grants') ?? false;
    $canBypassSelfRestrictions = auth()->user()?->can('bypass-self-restrictions') ?? false;
@endphp
@extends('layouts.admin')

@section('title', 'City Grants')

@section('content')
    <header class="nexus-page-header">
        <div class="nexus-page-header__copy">
            <h1 class="nexus-page-title">City grants</h1>
            <p class="nexus-page-summary">Review exact city funding requests, maintain tier requirements, and use manual disbursement only for deliberate exceptions.</p>
        </div>
        <div class="nexus-page-header__actions">
            <span class="nexus-status {{ $pendingCount > 0 ? 'nexus-status--warning' : 'nexus-status--success' }}">
                {{ number_format($pendingCount) }} pending
            </span>
            @can('manage-city-grants')
                <button class="btn btn-primary btn-outline btn-sm" type="button" onclick="document.getElementById('grantReminderModal').showModal()">
                    Send reminders
                </button>
            @endcan
        </div>
    </header>

    @unless($grantApprovalsEnabled)
        <div class="alert alert-warning" role="status">
            <x-icon name="o-pause-circle" class="size-5" aria-hidden="true" />
            <div>
                <p class="font-semibold">City grant approvals are paused</p>
                <p class="text-sm">Pending requests remain in the queue. Staff can deny them, but approval deposits are disabled by the global control.</p>
            </div>
        </div>
    @endunless

    <section class="nexus-panel nexus-panel--raised" aria-labelledby="pending-city-grants-title">
        <div class="nexus-panel__header">
            <div>
                <h2 id="pending-city-grants-title" class="nexus-section-title">Pending city grants</h2>
                <p class="nexus-body-muted mt-1">Approval immediately credits the requested account with the displayed amount.</p>
            </div>
            @unless($canManageCityGrants)
                <span class="nexus-status nexus-status--neutral">View only</span>
            @endunless
        </div>

        @forelse($pendingRequests as $request)
            @php
                $isOwnRequest = ! $canBypassSelfRestrictions
                    && auth()->user()?->nation_id !== null
                    && (int) auth()->user()->nation_id === (int) $request->nation_id;
            @endphp
            <article class="grid gap-4 border-b border-base-300 px-5 py-4 last:border-b-0 lg:grid-cols-[minmax(0,1fr)_minmax(13rem,0.62fr)_auto] lg:items-center">
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <h3 class="font-semibold">City #{{ $request->city_number }}</h3>
                        <span class="nexus-status nexus-status--warning">Pending</span>
                    </div>
                    @if ($request->nation)
                        <a href="https://politicsandwar.com/nation/id={{ $request->nation->id }}" target="_blank" rel="noopener noreferrer" class="mt-1 block w-fit font-medium text-primary hover:underline">
                            {{ $request->nation->leader_name ?? ('Nation #'.$request->nation->id) }}
                        </a>
                        <p class="text-sm nexus-text-muted">{{ $request->nation->nation_name ?? 'Unknown nation name' }} · Nation #{{ $request->nation_id }}</p>
                    @else
                        <p class="mt-1 text-sm nexus-text-muted">Unknown nation · Nation #{{ $request->nation_id }}</p>
                    @endif
                    <p class="mt-1 text-xs nexus-text-muted">
                        Requested <time datetime="{{ $request->created_at->toIso8601String() }}" class="tooltip tooltip-bottom cursor-help" data-tip="{{ $request->created_at->toDayDateTimeString() }}" tabindex="0" aria-label="Requested {{ $request->created_at->diffForHumans() }}, {{ $request->created_at->toDayDateTimeString() }}">{{ $request->created_at->diffForHumans() }}</time>
                        · Account #{{ $request->account_id }}
                    </p>
                </div>

                <div>
                    <p class="text-xs font-semibold uppercase tracking-wide nexus-text-muted">Deposit on approval</p>
                    <p class="mt-1 text-2xl font-bold tabular-nums">${{ number_format($request->grant_amount) }}</p>
                </div>

                <div class="flex flex-wrap gap-2 lg:justify-end">
                    @if($canManageCityGrants && ! $isOwnRequest)
                        @if($grantApprovalsEnabled)
                            <form action="{{ route('admin.grants.city.approve', $request) }}" method="POST" data-confirm="Approve this city grant and deposit the displayed amount into account #{{ $request->account_id }}?" data-confirm-title="Approve city grant?" data-confirm-label="Approve and deposit">
                                @csrf
                                <button type="submit" class="btn btn-success btn-sm">Approve and deposit</button>
                            </form>
                        @else
                            <span class="nexus-status nexus-status--warning">Approval paused</span>
                        @endif
                        <form action="{{ route('admin.grants.city.deny', $request) }}" method="POST" data-confirm="Deny this city grant request? The applicant will be notified and no funds will be deposited." data-confirm-title="Deny city grant request?" data-confirm-label="Deny request" data-confirm-tone="error">
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
                    <h3 class="font-semibold">City grant queue is clear</h3>
                    <p class="mt-1 text-sm nexus-text-muted">There are no pending city grant requests.</p>
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
            <p class="nexus-stat-helper">Completed payouts</p>
        </div>
        <div class="nexus-metric">
            <dt class="nexus-stat-label">Denied</dt>
            <dd class="nexus-stat-value">{{ number_format($totalDenied) }}</dd>
            <p class="nexus-stat-helper">Rejected requests</p>
        </div>
        <div class="nexus-metric">
            <dt class="nexus-stat-label">Funds distributed</dt>
            <dd class="nexus-stat-value">${{ number_format($totalFundsDistributed) }}</dd>
            <p class="nexus-stat-helper">Approved city funding</p>
        </div>
    </dl>

    @can('manage-city-grants')
        @can('manage-manual-disbursements')
        <details id="manual-city-grant-disbursement" class="nexus-panel" @if(old('city_grant_id') || old('nation_id') || old('account_id')) open @endif>
            <summary class="flex cursor-pointer list-none items-center justify-between gap-4 px-5 py-4 marker:hidden">
                <span>
                    <span class="block font-semibold">Manual city grant disbursement</span>
                    <span class="mt-0.5 block text-sm nexus-text-muted">Immediately pays a grant while bypassing pending and prior-award checks.</span>
                </span>
                <span class="flex items-center gap-2">
                    <span class="nexus-status {{ $grantApprovalsEnabled ? 'nexus-status--warning' : 'nexus-status--neutral' }}">
                        {{ $grantApprovalsEnabled ? 'Elevated action' : 'Paused' }}
                    </span>
                    <x-icon name="o-chevron-down" class="size-4 nexus-text-muted" aria-hidden="true" />
                </span>
            </summary>
            @if($grantApprovalsEnabled)
                <form method="POST" action="{{ route('admin.manual-disbursements.city-grants') }}" class="space-y-4 border-t border-base-300 p-5" data-confirm="Send this city grant immediately? This bypasses pending and prior-award checks." data-confirm-title="Send manual city grant?" data-confirm-label="Send city grant" data-confirm-tone="error">
                    @csrf
                    <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', (string) \Illuminate\Support\Str::uuid()) }}">
                    <div class="grid gap-4 md:grid-cols-3">
                        <label class="block space-y-2">
                            <span class="text-sm font-medium">City Grant</span>
                            <select name="city_grant_id" class="select w-full" required>
                                <option value="">Select a city grant</option>
                                @foreach($grants as $grant)
                                    @php
                                        $computedAmount = $grantAmounts[$grant->id] ?? null;
                                    @endphp
                                    <option value="{{ $grant->id }}" @selected(old('city_grant_id') == $grant->id)>
                                        City #{{ $grant->city_number }} —
                                        @if ($computedAmount !== null)
                                            ${{ number_format($computedAmount) }}
                                        @else
                                            Unavailable
                                        @endif
                                        ({{ number_format($grant->grant_amount) }}%)
                                    </option>
                                @endforeach
                            </select>
                        </label>

                        <label class="block space-y-2">
                            <span class="text-sm font-medium">Nation ID</span>
                            <input type="number" name="nation_id" class="input w-full" required min="1" value="{{ old('nation_id') }}">
                        </label>

                        <label class="block space-y-2">
                            <span class="text-sm font-medium">Account ID</span>
                            <input type="number" name="account_id" class="input w-full" required min="1" value="{{ old('account_id') }}">
                            <span class="text-xs nexus-text-muted">Must belong to the nation above.</span>
                        </label>
                    </div>

                    <div class="grid gap-4 md:grid-cols-2">
                        <label class="block space-y-2">
                            <span class="text-sm font-medium">City # Override (optional)</span>
                            <input type="number" name="city_number" class="input w-full" min="1" value="{{ old('city_number') }}" placeholder="Defaults to selected grant's city #">
                        </label>

                        <label class="block space-y-2">
                            <span class="text-sm font-medium">Grant Amount Override (optional)</span>
                            <input type="number" name="grant_amount" class="input w-full" min="1" value="{{ old('grant_amount') }}" placeholder="Defaults to the calculated grant amount">
                        </label>
                    </div>

                    <div class="nexus-form-actions">
                        <button class="btn btn-primary" type="submit">Send city grant immediately</button>
                    </div>
                </form>
            @else
                <div class="border-t border-base-300 p-5 text-sm text-base-content/65">
                    Manual city grant disbursements are unavailable while grant approvals are paused.
                </div>
            @endif
        </details>
        @endcan
    @endcan

    <section class="nexus-panel" aria-labelledby="city-grant-tiers-title">
        <div class="nexus-panel__header">
            <div>
                <h2 id="city-grant-tiers-title" class="nexus-section-title">City grant tiers</h2>
                <p class="nexus-body-muted mt-1">Calculated funding, availability, and eligibility requirements for each city level.</p>
            </div>
            @can('manage-city-grants')
                <button class="btn btn-primary btn-sm" type="button" data-city-grant-create>Create city grant</button>
            @endcan
        </div>
        <div class="overflow-x-auto">
            <table class="nexus-table" data-sortable="true">
                <thead>
                <tr>
                    <th>City #</th>
                    <th>Amount</th>
                    <th>Status</th>
                    <th>Description</th>
                    <th>Requirements</th>
                    <th data-sortable="false">Actions</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($grants as $grant)
                    <tr>
                        <td data-order="{{ $grant->city_number }}">{{ $grant->city_number }}</td>
                        <td>
                            @php
                                $computedAmount = $grantAmounts[$grant->id] ?? null;
                            @endphp
                            @if ($computedAmount !== null)
                                ${{ number_format($computedAmount) }}
                            @else
                                Unavailable
                            @endif
                            <span class="nexus-text-muted">({{ number_format($grant->grant_amount) }}%)</span>
                        </td>
                        <td data-order="{{ $grant->enabled ? 1 : 0 }}">
                            <span class="nexus-status {{ $grant->enabled ? 'nexus-status--success' : 'nexus-status--neutral' }}">
                                {{ $grant->enabled ? 'Enabled' : 'Disabled' }}
                            </span>
                        </td>
                        <td>{{ $grant->description }}</td>
                        <td>
                            @if (!empty($grant->requirement_summary))
                                <div class="flex flex-wrap gap-1">
                                    @foreach (array_slice($grant->requirement_summary, 0, 3) as $summary)
                                        <x-badge :value="$summary" class="badge-ghost badge-sm" />
                                    @endforeach
                                    @if (count($grant->requirement_summary) > 3)
                                        <x-badge value="+{{ count($grant->requirement_summary) - 3 }} more" class="badge-neutral badge-sm" />
                                    @endif
                                </div>
                            @else
                                <span class="text-sm nexus-text-muted">No custom requirements</span>
                            @endif
                        </td>
                        <td>
                            @can('manage-city-grants')
                                <button class="btn btn-primary btn-outline btn-sm" type="button" data-city-grant-edit="{{ $grant->id }}">Edit</button>
                            @else
                                <span class="nexus-text-muted">—</span>
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="py-8 text-center nexus-text-muted">No city grant tiers have been configured.</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>

    @can('manage-city-grants')
    <dialog class="modal modal-bottom sm:modal-middle" aria-labelledby="city-grant-modal-title" data-city-grant-modal>
        <div class="modal-box max-h-[90vh] w-11/12 max-w-5xl overflow-y-auto">
            <form method="POST" data-city-grant-form>
                @csrf
                <input type="hidden" name="id" data-city-grant-field="id">

                <div class="mb-4 flex items-start justify-between gap-4">
                    <div>
                        <h3 class="text-lg font-semibold" id="city-grant-modal-title" data-city-grant-modal-title>Manage City Grant</h3>
                        <p class="text-sm nexus-text-muted">Configure city funding and the complete eligibility rule tree.</p>
                    </div>
                    <button type="button" class="btn btn-sm btn-ghost" data-city-grant-close>Close</button>
                </div>

                <div class="grid grid-cols-1 gap-4 lg:grid-cols-5">
                    <div class="rounded-box space-y-4 bg-base-200 p-4 lg:col-span-2">
                        <div class="font-semibold">City grant basics</div>

                        <label class="block space-y-2">
                            <span class="text-sm font-medium">City Number</span>
                            <input type="number" class="input w-full" name="city_number" required data-city-grant-field="city_number">
                        </label>

                        <label class="block space-y-2">
                            <span class="text-sm font-medium">Grant Percentage</span>
                            <input type="number" class="input w-full" name="grant_amount" min="1" step="1" required data-city-grant-field="grant_amount">
                        </label>

                        <label class="block space-y-2">
                            <span class="text-sm font-medium">Status</span>
                            <select class="select w-full" name="enabled" data-city-grant-field="enabled">
                                <option value="1">Enabled</option>
                                <option value="0">Disabled</option>
                            </select>
                        </label>

                        <label class="block space-y-2">
                            <span class="text-sm font-medium">Description</span>
                            <textarea class="textarea min-h-28 w-full" name="description" data-city-grant-field="description"></textarea>
                        </label>
                    </div>

                    <div class="lg:col-span-3">
                        @include('admin.grants.partials.requirement-builder', [
                            'inputName' => 'requirements_json',
                            'builderConfig' => $grantRequirementBuilderConfig,
                            'showValidationErrors' => $oldCityGrantPayload && $errors->any(),
                            'emptySummaryHint' => 'Applications will only enforce the standard alliance, pending request, and prior-award checks until you add rules.',
                        ])
                    </div>
                </div>

                <div class="modal-action">
                    <button type="button" class="btn btn-ghost" data-city-grant-close>Cancel</button>
                    <button type="submit" class="btn btn-primary">Save City Grant</button>
                </div>
            </form>
        </div>
        <form method="dialog" class="modal-backdrop"><button>close</button></form>
    </dialog>
    @endcan

    @can('manage-city-grants')
        @php
            $selectedGrantIds = collect(old('grant_ids', $grants->where('enabled', true)->pluck('id')->all()))
                ->map(fn ($id) => (int) $id)
                ->all();
        @endphp
        <dialog id="grantReminderModal" class="modal" aria-label="Send city grant reminders">
            <div class="modal-box max-w-4xl">
                <form method="POST" action="{{ route('admin.grants.city.reminders') }}" id="grantReminderForm" class="space-y-4">
                    @csrf

                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <h3 class="text-lg font-semibold">Send City Grant Reminders</h3>
                            <p class="text-sm nexus-text-muted">Select grant tiers and queue reminder mails for eligible applicants.</p>
                        </div>
                        <button type="button" class="btn btn-sm btn-ghost" onclick="document.getElementById('grantReminderModal').close()">Close</button>
                    </div>

                    @if ($errors->has('grant_ids') || $errors->has('message'))
                        <div class="alert alert-error">
                            <div class="space-y-1 text-sm">
                                @foreach ($errors->all() as $error)
                                    <div>{{ $error }}</div>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    <div class="space-y-3">
                        <div class="flex flex-wrap gap-2">
                            <button type="button" class="btn btn-ghost btn-sm" data-grant-reminder-select="all">Select All</button>
                            <button type="button" class="btn btn-ghost btn-sm" data-grant-reminder-select="none">Select None</button>
                        </div>
                        <div class="grid gap-3 md:grid-cols-2">
                            @foreach ($grants->sortBy('city_number') as $grant)
                                <label class="flex items-center gap-3 rounded-box border border-base-300 px-4 py-3">
                                    <input class="checkbox checkbox-primary" type="checkbox" name="grant_ids[]" value="{{ $grant->id }}" id="grant-reminder-{{ $grant->id }}" @checked(in_array($grant->id, $selectedGrantIds, true))>
                                    <span class="text-sm">
                                        City #{{ $grant->city_number }}
                                        @if (! $grant->enabled)
                                            <span class="nexus-text-muted">(Disabled)</span>
                                        @endif
                                    </span>
                                </label>
                            @endforeach
                        </div>
                    </div>

                    <div class="space-y-3">
                        <label class="block space-y-2">
                            <span class="text-sm font-medium">Admin Message</span>
                            <div class="rounded-box border border-base-300 bg-base-200/60 p-3 text-sm nexus-text-muted">
                                <div>Hi {leader_name},</div>
                                <div class="mt-2">[Your message below]</div>
                                <div class="mt-2">Please click [link={link to apply for city grants}]here[/link] to apply for a city grant</div>
                            </div>
                            <textarea class="textarea min-h-40 w-full" rows="6" id="grantReminderMessage" name="message" required>{{ old('message', $defaultReminderMessage) }}</textarea>
                            <span class="text-xs nexus-text-muted">We automatically add a greeting and the application link after this message.</span>
                        </label>
                    </div>

                    <div class="flex justify-end gap-2">
                        <button type="button" class="btn btn-ghost" onclick="document.getElementById('grantReminderModal').close()">Cancel</button>
                        <button type="submit" class="btn btn-primary">Queue Reminders</button>
                    </div>
                </form>
            </div>
            <form method="dialog" class="modal-backdrop"><button>close</button></form>
        </dialog>
    @endcan
@endsection

@push("scripts")
    <script>
        (() => {
            const cityGrants = new Map(
                {{ Illuminate\Support\Js::from($grants->values()) }}.map((grant) => [String(grant.id), grant]),
            );
            const oldCityGrantPayload = {{ Illuminate\Support\Js::from($oldCityGrantPayload) }};
            const shouldReopenReminderModal = @json($errors->has('grant_ids') || $errors->has('message'));

            function setBuilderValue(form, value) {
                const builderRoot = form.querySelector('[data-grant-requirement-builder]');

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

            function populateCityGrantForm(form, grant = {}) {
                form.reset();
                form.querySelector('[data-city-grant-field="id"]').value = grant.id || '';
                form.querySelector('[data-city-grant-field="city_number"]').value = grant.city_number ?? '';
                form.querySelector('[data-city-grant-field="grant_amount"]').value = grant.grant_amount ?? 100;
                form.querySelector('[data-city-grant-field="enabled"]').value = [false, 0, '0'].includes(grant.enabled) ? '0' : '1';
                form.querySelector('[data-city-grant-field="description"]').value = grant.description || '';
                setBuilderValue(form, grant.requirements ?? grant.requirements_json ?? null);
            }

            function openCityGrantForm(grant = null) {
                const modal = document.querySelector('[data-city-grant-modal]');
                const form = modal?.querySelector('[data-city-grant-form]');
                const title = modal?.querySelector('[data-city-grant-modal-title]');

                if (!modal || !form || !title) {
                    return;
                }

                if (grant?.id) {
                    form.action = `{{ url('admin/grants/city') }}/${grant.id}/update`;
                    title.textContent = 'Edit City Grant';
                    populateCityGrantForm(form, grant);
                } else {
                    form.action = `{{ url('admin/grants/city/create') }}`;
                    title.textContent = 'Create City Grant';
                    populateCityGrantForm(form);
                }

                if (!modal.open) {
                    modal.showModal();
                }
            }

            function setGrantReminderSelection(isChecked) {
                document.querySelectorAll('#grantReminderForm input[name="grant_ids[]"]').forEach((input) => {
                    input.checked = isChecked;
                });
            }

            function initCityGrantAdminPage() {
                document.querySelectorAll('[data-city-grant-create]').forEach((button) => {
                    if (button.dataset.bound === 'true') {
                        return;
                    }

                    button.dataset.bound = 'true';
                    button.addEventListener('click', () => openCityGrantForm());
                });

                document.querySelectorAll('[data-city-grant-edit]').forEach((button) => {
                    if (button.dataset.bound === 'true') {
                        return;
                    }

                    button.dataset.bound = 'true';
                    button.addEventListener('click', () => {
                        const grant = cityGrants.get(button.dataset.cityGrantEdit || '');

                        if (grant) {
                            openCityGrantForm(grant);
                        }
                    });
                });

                document.querySelectorAll('[data-city-grant-close]').forEach((button) => {
                    if (button.dataset.bound === 'true') {
                        return;
                    }

                    button.dataset.bound = 'true';
                    button.addEventListener('click', () => {
                        button.closest('dialog')?.close();
                    });
                });

                document.querySelectorAll('[data-grant-reminder-select]').forEach((button) => {
                    if (button.dataset.bound === 'true') {
                        return;
                    }

                    button.dataset.bound = 'true';
                    button.addEventListener('click', () => {
                        setGrantReminderSelection(button.dataset.grantReminderSelect === 'all');
                    });
                });

                const cityGrantForm = document.querySelector('[data-city-grant-form]');

                if (oldCityGrantPayload && cityGrantForm?.dataset.validationReopened !== 'true') {
                    cityGrantForm.dataset.validationReopened = 'true';
                    openCityGrantForm(oldCityGrantPayload);
                }

                const reminderModal = document.getElementById('grantReminderModal');

                if (
                    shouldReopenReminderModal
                    && !oldCityGrantPayload
                    && reminderModal?.dataset.validationReopened !== 'true'
                ) {
                    reminderModal.dataset.validationReopened = 'true';
                    reminderModal.showModal();
                }
            }

            window.initCityGrantAdminPage = initCityGrantAdminPage;

            if (document.documentElement.dataset.cityGrantLifecycleBound !== 'true') {
                document.documentElement.dataset.cityGrantLifecycleBound = 'true';
                document.addEventListener('codex:page-ready', () => window.initCityGrantAdminPage?.());
            }

            initCityGrantAdminPage();
        })();
    </script>
@endpush
