@php
    use App\Services\PWHelperService;

    $defaultReminderMessage = 'You are eligible for a city grant to help cover the cost of your next city. '
        .'If you are planning to expand soon, please submit an application so we can review it promptly.';
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
                        <p class="text-sm text-base-content/60">{{ $request->nation->nation_name ?? 'Unknown nation name' }} · Nation #{{ $request->nation_id }}</p>
                    @else
                        <p class="mt-1 text-sm text-base-content/60">Unknown nation · Nation #{{ $request->nation_id }}</p>
                    @endif
                    <p class="mt-1 text-xs text-base-content/55">
                        Requested <time datetime="{{ $request->created_at->toIso8601String() }}" class="tooltip tooltip-bottom cursor-help" data-tip="{{ $request->created_at->toDayDateTimeString() }}" tabindex="0" aria-label="Requested {{ $request->created_at->diffForHumans() }}, {{ $request->created_at->toDayDateTimeString() }}">{{ $request->created_at->diffForHumans() }}</time>
                        · Account #{{ $request->account_id }}
                    </p>
                </div>

                <div>
                    <p class="text-xs font-semibold uppercase tracking-wide text-base-content/55">Deposit on approval</p>
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
                            <span class="mt-1 block text-base-content/60">Another reviewer must decide.</span>
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
                    <p class="mt-1 text-sm text-base-content/60">There are no pending city grant requests.</p>
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
        <details id="manual-city-grant-disbursement" class="nexus-panel" @if(old('city_grant_id') || old('nation_id') || old('account_id')) open @endif>
            <summary class="flex cursor-pointer list-none items-center justify-between gap-4 px-5 py-4 marker:hidden">
                <span>
                    <span class="block font-semibold">Manual city grant disbursement</span>
                    <span class="mt-0.5 block text-sm text-base-content/60">Immediately pays a grant while bypassing pending and prior-award checks.</span>
                </span>
                <span class="flex items-center gap-2">
                    <span class="nexus-status {{ $grantApprovalsEnabled ? 'nexus-status--warning' : 'nexus-status--neutral' }}">
                        {{ $grantApprovalsEnabled ? 'Elevated action' : 'Paused' }}
                    </span>
                    <x-icon name="o-chevron-down" class="size-4 text-base-content/50" aria-hidden="true" />
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
                            <span class="text-xs text-base-content/60">Must belong to the nation above.</span>
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

    <section class="nexus-panel" aria-labelledby="city-grant-tiers-title">
        <div class="nexus-panel__header">
            <div>
                <h2 id="city-grant-tiers-title" class="nexus-section-title">City grant tiers</h2>
                <p class="nexus-body-muted mt-1">Calculated funding, availability, and required projects for each city level.</p>
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
                    <th>Required Projects</th>
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
                            <span class="text-base-content/60">({{ number_format($grant->grant_amount) }}%)</span>
                        </td>
                        <td data-order="{{ $grant->enabled ? 1 : 0 }}">
                            <span class="nexus-status {{ $grant->enabled ? 'nexus-status--success' : 'nexus-status--neutral' }}">
                                {{ $grant->enabled ? 'Enabled' : 'Disabled' }}
                            </span>
                        </td>
                        <td>{{ $grant->description }}</td>
                        <td>
                            @if(isset($grant->requirements['required_projects']))
                                <div class="flex flex-wrap gap-2">
                                    @foreach($grant->requirements['required_projects'] as $project)
                                        <x-badge :value="$project" class="badge-primary badge-outline badge-sm" />
                                    @endforeach
                                </div>
                            @else
                                <span class="text-base-content/60">None</span>
                            @endif
                        </td>
                        <td>
                            @can('manage-city-grants')
                                <button class="btn btn-primary btn-outline btn-sm" type="button" data-city-grant-edit='@json($grant)'>Edit</button>
                            @else
                                <span class="text-base-content/50">—</span>
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="py-8 text-center text-base-content/60">No city grant tiers have been configured.</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>

    @can('manage-city-grants')
    <dialog id="grantModal" class="modal" aria-labelledby="grantModalLabel">
        <div class="modal-box max-w-3xl">
            <form id="grantForm" method="POST" class="space-y-4">
                @csrf
                <input type="hidden" name="id" id="grant_id">

                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h3 class="text-lg font-semibold" id="grantModalLabel">Manage City Grant</h3>
                        <p class="text-sm text-base-content/60">Adjust thresholds, status, and project requirements.</p>
                    </div>
                    <button type="button" class="btn btn-sm btn-ghost" onclick="document.getElementById('grantModal').close()">Close</button>
                </div>

                <label class="block space-y-2">
                    <span class="text-sm font-medium">City Number</span>
                    <input type="number" class="input w-full" name="city_number" id="city_number" required>
                </label>

                <label class="block space-y-2">
                    <span class="text-sm font-medium">Grant Percentage</span>
                    <input type="number" class="input w-full" name="grant_amount" id="grant_amount" min="1" step="1" required>
                </label>

                <label class="block space-y-2">
                    <span class="text-sm font-medium">Status</span>
                    <select class="select w-full" name="enabled" id="enabled">
                        <option value="1">Enabled</option>
                        <option value="0">Disabled</option>
                    </select>
                </label>

                <label class="block space-y-2">
                    <span class="text-sm font-medium">Required Projects</span>
                    <select class="select min-h-40 w-full" name="projects[]" id="projects" multiple>
                        @foreach (array_keys(PWHelperService::PROJECTS) as $project)
                            <option value="{{ $project }}">{{ $project }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="block space-y-2">
                    <span class="text-sm font-medium">Description</span>
                    <textarea class="textarea min-h-28 w-full" name="description" id="description"></textarea>
                </label>

                <div class="flex justify-end gap-2">
                    <button type="button" class="btn btn-ghost" onclick="document.getElementById('grantModal').close()">Close</button>
                    <button type="submit" class="btn btn-primary">Save</button>
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
                            <p class="text-sm text-base-content/60">Select grant tiers and queue reminder mails for eligible applicants.</p>
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
                                            <span class="text-base-content/60">(Disabled)</span>
                                        @endif
                                    </span>
                                </label>
                            @endforeach
                        </div>
                    </div>

                    <div class="space-y-3">
                        <label class="block space-y-2">
                            <span class="text-sm font-medium">Admin Message</span>
                            <div class="rounded-box border border-base-300 bg-base-200/60 p-3 text-sm text-base-content/60">
                                <div>Hi {leader_name},</div>
                                <div class="mt-2">[Your message below]</div>
                                <div class="mt-2">Please click [link={link to apply for city grants}]here[/here] to apply for a city grant</div>
                            </div>
                            <textarea class="textarea min-h-40 w-full" rows="6" id="grantReminderMessage" name="message" required>{{ old('message', $defaultReminderMessage) }}</textarea>
                            <span class="text-xs text-base-content/60">We automatically add a greeting and the application link after this message.</span>
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
        function editGrant(grant) {
            document.getElementById('grantForm').action = `{{ url('admin/grants/city') }}/${grant.id}/update`;
            document.getElementById('grant_id').value = grant.id || '';
            document.getElementById('city_number').value = grant.city_number || '';
            document.getElementById('grant_amount').value = grant.grant_amount || '';
            document.getElementById('enabled').value = grant.enabled ? '1' : '0';
            document.getElementById('description').value = grant.description || '';

            const projectSelect = document.getElementById('projects');
            for (const option of projectSelect.options) {
                option.selected = grant.requirements?.required_projects?.includes(option.value) || false;
            }

            document.getElementById('grantModalLabel').textContent = 'Edit City Grant';
            document.getElementById('grantModal').showModal();
        }

        function clearGrantForm() {
            document.getElementById('grantForm').action = `{{ url('admin/grants/city/create') }}`;
            document.getElementById('grantForm').reset();
            document.getElementById('grant_id').value = '';
            document.getElementById('grant_amount').value = 100;
            document.getElementById('grantModalLabel').textContent = 'Create City Grant';
            document.getElementById('grantModal').showModal();
        }

        function setGrantReminderSelection(isChecked) {
            document.querySelectorAll('input[name="grant_ids[]"]').forEach((input) => {
                input.checked = isChecked;
            });
        }

        function initCityGrantAdminPage() {
            document.querySelectorAll('[data-city-grant-create]').forEach((button) => {
                if (button.dataset.bound === 'true') {
                    return;
                }

                button.dataset.bound = 'true';
                button.addEventListener('click', () => clearGrantForm());
            });

            document.querySelectorAll('[data-city-grant-edit]').forEach((button) => {
                if (button.dataset.bound === 'true') {
                    return;
                }

                button.dataset.bound = 'true';
                button.addEventListener('click', () => {
                    editGrant(JSON.parse(button.dataset.cityGrantEdit || '{}'));
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
        }

        document.addEventListener('codex:page-ready', initCityGrantAdminPage);
        initCityGrantAdminPage();

        @can('manage-city-grants')
            @if ($errors->has('grant_ids') || $errors->has('message'))
                document.addEventListener('codex:page-ready', () => {
                    document.getElementById('grantReminderModal')?.showModal();
                });
            @endif
        @endcan
    </script>
@endpush
