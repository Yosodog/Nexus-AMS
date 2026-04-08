@php
    use App\Services\PWHelperService;

    $defaultReminderMessage = 'You are eligible for a city grant to help cover the cost of your next city. '
        .'If you are planning to expand soon, please submit an application so we can review it promptly.';
@endphp
@extends('layouts.admin')

@section('content')
    <x-header title="City Grant Management" separator>
        <x-slot:subtitle>Review pending requests, issue manual disbursements, and maintain grant thresholds from one place.</x-slot:subtitle>
        <x-slot:actions>
            @can('manage-city-grants')
                <button class="btn btn-primary btn-outline btn-sm" type="button" onclick="document.getElementById('grantReminderModal').showModal()">
                    Send Reminders
                </button>
            @endcan
        </x-slot:actions>
    </x-header>

    <div class="mb-6 grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        <x-stat title="Approved Grants" :value="number_format($totalApproved)" icon="o-check-circle" color="text-primary" description="Completed city grant payouts" />
        <x-stat title="Denied Grants" :value="number_format($totalDenied)" icon="o-x-circle" color="text-error" description="Requests rejected by staff" />
        <x-stat title="Pending Grants" :value="number_format($pendingCount)" icon="o-clock" color="text-warning" description="Waiting for review" />
        <x-stat title="Funds Distributed" :value="'$' . number_format($totalFundsDistributed)" icon="o-banknotes" color="text-success" description="Total city grant outflow" />
    </div>

    <x-card title="Pending City Grants" class="mb-6">
        <div class="overflow-x-auto">
            @if($pendingRequests->isEmpty())
                <p class="py-6 text-base-content/60">No pending grant requests.</p>
            @else
                <table class="table table-zebra">
                    <thead>
                    <tr>
                        <th>City #</th>
                        <th>Nation</th>
                        <th>Amount</th>
                        <th>Requested At</th>
                        <th data-sortable="false">Actions</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($pendingRequests as $request)
                        <tr>
                            <td>{{ $request->city_number }}</td>
                            <td>
                                @if ($request->nation)
                                    <a href="https://politicsandwar.com/nation/id={{ $request->nation->id }}" target="_blank" rel="noopener noreferrer" class="link link-primary">
                                        {{ $request->nation->leader_name ?? ('Nation #'.$request->nation->id) }}
                                    </a>
                                    <div class="text-sm text-base-content/60">{{ $request->nation->nation_name ?? 'Unknown Nation' }}</div>
                                @else
                                    <span class="text-base-content/60">Unknown Nation</span>
                                @endif
                            </td>
                            <td>${{ number_format($request->grant_amount) }}</td>
                            <td data-order="{{ $request->created_at->timestamp }}">{{ $request->created_at->format('M d, Y') }}</td>
                            <td>
                                <div class="flex flex-wrap gap-2">
                                    <form action="{{ route('admin.grants.city.approve', $request) }}" method="POST">
                                        @csrf
                                        <button type="submit" class="btn btn-success btn-sm">Approve</button>
                                    </form>
                                    <form action="{{ route('admin.grants.city.deny', $request) }}" method="POST">
                                        @csrf
                                        <button type="submit" class="btn btn-error btn-outline btn-sm">Deny</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </x-card>

    @can('manage-city-grants')
        <x-card title="Manual City Grant Disbursement" class="mb-6">
            <p class="mb-4 text-sm text-base-content/60">
                Approves and pays a city grant immediately, bypassing pending or prior award checks. Use when admins need to push funds without a request.
            </p>
            <form method="POST" action="{{ route('admin.manual-disbursements.city-grants') }}" class="space-y-4">
                @csrf
                <div class="grid gap-4 md:grid-cols-3">
                    <label class="block space-y-2">
                        <span class="text-sm font-medium">City Grant</span>
                        <select name="city_grant_id" class="select select-bordered w-full" required>
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
                        <input type="number" name="nation_id" class="input input-bordered w-full" required min="1" value="{{ old('nation_id') }}">
                    </label>

                    <label class="block space-y-2">
                        <span class="text-sm font-medium">Account ID</span>
                        <input type="number" name="account_id" class="input input-bordered w-full" required min="1" value="{{ old('account_id') }}">
                        <span class="text-xs text-base-content/60">Must belong to the nation above.</span>
                    </label>
                </div>

                <div class="grid gap-4 md:grid-cols-2">
                    <label class="block space-y-2">
                        <span class="text-sm font-medium">City # Override (optional)</span>
                        <input type="number" name="city_number" class="input input-bordered w-full" min="1" value="{{ old('city_number') }}" placeholder="Defaults to selected grant's city #">
                    </label>

                    <label class="block space-y-2">
                        <span class="text-sm font-medium">Grant Amount Override (optional)</span>
                        <input type="number" name="grant_amount" class="input input-bordered w-full" min="1" value="{{ old('grant_amount') }}" placeholder="Defaults to the calculated grant amount">
                    </label>
                </div>

                <div class="flex justify-end">
                    <button class="btn btn-primary" type="submit">Send City Grant</button>
                </div>
            </form>
        </x-card>
    @endcan

    <x-card>
        <x-slot:title>
            <div>
                City Grants
                <div class="text-sm font-normal text-base-content/60">Sortable overview of every city grant tier and its project requirements.</div>
            </div>
        </x-slot:title>
        <x-slot:menu>
            <button class="btn btn-primary btn-sm" type="button" data-city-grant-create>
                Create New Grant
            </button>
        </x-slot:menu>
        <div class="overflow-x-auto">
            <table class="table table-zebra">
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
                @foreach ($grants as $grant)
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
                            <x-badge value="{{ $grant->enabled ? 'Enabled' : 'Disabled' }}" class="{{ $grant->enabled ? 'badge-success badge-sm' : 'badge-ghost badge-sm' }}" />
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
                            <button class="btn btn-primary btn-outline btn-sm" type="button" data-city-grant-edit='@json($grant)'>
                                Edit
                            </button>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </x-card>

    <dialog id="grantModal" class="modal">
        <div class="modal-box max-w-3xl">
            <form id="grantForm" method="POST" class="space-y-4">
                @csrf
                <input type="hidden" name="id" id="grant_id">

                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h3 class="text-lg font-semibold" id="grantModalLabel">Manage City Grant</h3>
                        <p class="text-sm text-base-content/60">Adjust thresholds, status, and project requirements.</p>
                    </div>
                    <button type="button" class="btn btn-sm btn-circle btn-ghost" onclick="document.getElementById('grantModal').close()">✕</button>
                </div>

                <label class="block space-y-2">
                    <span class="text-sm font-medium">City Number</span>
                    <input type="number" class="input input-bordered w-full" name="city_number" id="city_number" required>
                </label>

                <label class="block space-y-2">
                    <span class="text-sm font-medium">Grant Percentage</span>
                    <input type="number" class="input input-bordered w-full" name="grant_amount" id="grant_amount" min="1" step="1" required>
                </label>

                <label class="block space-y-2">
                    <span class="text-sm font-medium">Status</span>
                    <select class="select select-bordered w-full" name="enabled" id="enabled">
                        <option value="1">Enabled</option>
                        <option value="0">Disabled</option>
                    </select>
                </label>

                <label class="block space-y-2">
                    <span class="text-sm font-medium">Required Projects</span>
                    <select class="select select-bordered min-h-40 w-full" name="projects[]" id="projects" multiple>
                        @foreach (array_keys(PWHelperService::PROJECTS) as $project)
                            <option value="{{ $project }}">{{ $project }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="block space-y-2">
                    <span class="text-sm font-medium">Description</span>
                    <textarea class="textarea textarea-bordered min-h-28 w-full" name="description" id="description"></textarea>
                </label>

                <div class="flex justify-end gap-2">
                    <button type="button" class="btn btn-ghost" onclick="document.getElementById('grantModal').close()">Close</button>
                    <button type="submit" class="btn btn-primary">Save</button>
                </div>
            </form>
        </div>
        <form method="dialog" class="modal-backdrop"><button>close</button></form>
    </dialog>

    @can('manage-city-grants')
        @php
            $selectedGrantIds = collect(old('grant_ids', $grants->where('enabled', true)->pluck('id')->all()))
                ->map(fn ($id) => (int) $id)
                ->all();
        @endphp
        <dialog id="grantReminderModal" class="modal">
            <div class="modal-box max-w-4xl">
                <form method="POST" action="{{ route('admin.grants.city.reminders') }}" id="grantReminderForm" class="space-y-4">
                    @csrf

                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <h3 class="text-lg font-semibold">Send City Grant Reminders</h3>
                            <p class="text-sm text-base-content/60">Select grant tiers and queue reminder mails for eligible applicants.</p>
                        </div>
                        <button type="button" class="btn btn-sm btn-circle btn-ghost" onclick="document.getElementById('grantReminderModal').close()">✕</button>
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
                            <textarea class="textarea textarea-bordered min-h-40 w-full" rows="6" id="grantReminderMessage" name="message" required>{{ old('message', $defaultReminderMessage) }}</textarea>
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
