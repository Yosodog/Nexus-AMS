@php
    use App\Services\PWHelperService;

    $defaultReminderMessage = 'You are eligible for a city grant to help cover the cost of your next city. '
        .'If you are planning to expand soon, please submit an application so we can review it promptly.';
@endphp
@extends('layouts.admin')

@section("content")
    <div class="app-content-header">
        <div class="container-fluid">
            <div class="row align-items-center">
                <div class="col-sm-6">
                    <h3 class="mb-0">City Grant Management</h3>
                </div>
                @can('manage-city-grants')
                    <div class="col-sm-6 text-sm-end mt-2 mt-sm-0">
                        <button class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#grantReminderModal">
                            Send City Grant Reminders
                        </button>
                    </div>
                @endcan
            </div>
        </div>
    </div>

    {{-- Info Boxes --}}
    <div class="row">
        <div class="col-md-3">
            <x-admin.info-box icon="bi bi-check-circle" bgColor="text-bg-primary" title="Total Approved Grants"
                              :value="$totalApproved"/>
        </div>
        <div class="col-md-3">
            <x-admin.info-box icon="bi bi-x-circle" bgColor="text-bg-danger" title="Total Denied Grants"
                              :value="$totalDenied"/>
        </div>
        <div class="col-md-3">
            <x-admin.info-box icon="bi bi-hourglass-split" bgColor="text-bg-warning" title="Pending Grants"
                              :value="$pendingCount"/>
        </div>
        <div class="col-md-3">
            <x-admin.info-box icon="bi bi-cash" bgColor="text-bg-success" title="Total Funds Distributed"
                              :value="number_format($totalFundsDistributed)"/>
        </div>
    </div>

    {{-- Pending Grants --}}
    <div class="card mt-4">
        <div class="card-header">Pending City Grants</div>
        <div class="card-body">
            @if($pendingRequests->isEmpty())
                <p>No pending grant requests.</p>
            @else
                <table class="table table-striped">
                    <thead>
                    <tr>
                        <th>City #</th>
                        <th>Nation</th>
                        <th>Amount</th>
                        <th>Requested At</th>
                        <th>Actions</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($pendingRequests as $request)
                        <tr>
                            <td>{{ $request->city_number }}</td>
                            <td>{{ $request->nation->nation_name }}</td>
                            <td>${{ number_format($request->grant_amount) }}</td>
                            <td>{{ $request->created_at->format('M d, Y') }}</td>
                            <td>
                                <form action="{{ route('admin.grants.city.approve', $request) }}" method="POST"
                                      class="d-inline">
                                    @csrf
                                    <button type="submit" class="btn btn-success btn-sm">Approve</button>
                                </form>
                                <form action="{{ route('admin.grants.city.deny', $request) }}" method="POST"
                                      class="d-inline">
                                    @csrf
                                    <button type="submit" class="btn btn-danger btn-sm">Deny</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </div>

    @can('manage-city-grants')
        <div class="card mt-4">
            <div class="card-header">Manual City Grant Disbursement</div>
            <div class="card-body">
                <p class="text-muted small mb-3">
                    Approves and pays a city grant immediately, bypassing pending or prior award checks. Use when admins need to push funds without a request.
                </p>
                <form method="POST" action="{{ route('admin.manual-disbursements.city-grants') }}">
                    @csrf
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">City Grant</label>
                            <select name="city_grant_id" class="form-select" required>
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
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Nation ID</label>
                            <input type="number" name="nation_id" class="form-control" required min="1" value="{{ old('nation_id') }}">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Account ID</label>
                            <input type="number" name="account_id" class="form-control" required min="1" value="{{ old('account_id') }}">
                            <small class="text-muted">Must belong to the nation above.</small>
                        </div>
                    </div>
                    <div class="row g-3 mt-1">
                        <div class="col-md-6">
                            <label class="form-label">City # Override (optional)</label>
                            <input type="number" name="city_number" class="form-control" min="1" value="{{ old('city_number') }}"
                                   placeholder="Defaults to selected grant's city #">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Grant Amount Override (optional)</label>
                            <input type="number" name="grant_amount" class="form-control" min="1" value="{{ old('grant_amount') }}"
                                   placeholder="Defaults to the calculated grant amount">
                        </div>
                    </div>
                    <div class="d-flex justify-content-end mt-3">
                        <button class="btn btn-primary" type="submit">Send City Grant</button>
                    </div>
                </form>
            </div>
        </div>
    @endcan

    {{-- City Grants List --}}
    <div class="card mt-4">
        <div class="card-header">City Grants</div>
        <div class="card-body">
            <table class="table table-striped">
                <thead>
                <tr>
                    <th>City #</th>
                        <th>Amount</th>
                        <th>Status</th>
                        <th>Description</th>
                        <th>Required Projects</th>
                        <th>Actions</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($grants as $grant)
                    <tr>
                        <td>{{ $grant->city_number }}</td>
                        <td>
                            @php
                                $computedAmount = $grantAmounts[$grant->id] ?? null;
                            @endphp
                            @if ($computedAmount !== null)
                                ${{ number_format($computedAmount) }}
                            @else
                                Unavailable
                            @endif
                            <span class="text-muted">({{ number_format($grant->grant_amount) }}%)</span>
                        </td>
                        <td>{{ $grant->enabled ? 'Enabled' : 'Disabled' }}</td>
                        <td>{{ $grant->description }}</td>
                        <td>
                            @if(isset($grant->requirements['required_projects']))
                                {{ implode(', ', $grant->requirements['required_projects']) }}
                            @else
                                None
                            @endif
                        </td>
                        <td>
                            <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#grantModal"
                                    onclick="editGrant({{ json_encode($grant) }})">Edit
                            </button>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            <button class="btn btn-primary mt-3" data-bs-toggle="modal" data-bs-target="#grantModal"
                    onclick="clearGrantForm()">Create New Grant
            </button>
        </div>
    </div>

    {{-- Grant Modal --}}
    <div class="modal modal-lg fade" id="grantModal" tabindex="-1" aria-labelledby="grantModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="grantModalLabel">Manage City Grant</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="grantForm" method="POST">
                        @csrf
                        <input type="hidden" name="id" id="grant_id">
                        <div class="mb-3">
                            <label for="city_number" class="form-label">City Number</label>
                            <input type="number" class="form-control" name="city_number" id="city_number" required>
                        </div>
                        <div class="mb-3">
                            <label for="grant_amount" class="form-label">Grant Percentage</label>
                            <input type="number" class="form-control" name="grant_amount" id="grant_amount" min="1" step="1" required>
                        </div>
                        <div class="mb-3">
                            <label for="enabled" class="form-label">Status</label>
                            <select class="form-control" name="enabled" id="enabled">
                                <option value="1">Enabled</option>
                                <option value="0">Disabled</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="projects" class="form-label">Required Projects</label>
                            <select class="form-control" name="projects[]" id="projects" multiple>
                                @foreach (array_keys(PWHelperService::PROJECTS) as $project)
                                    <option value="{{ $project }}">{{ $project }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="description" class="form-label">Description</label>
                            <textarea class="form-control" name="description" id="description"></textarea>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                            <button type="submit" class="btn btn-primary">Save</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    @can('manage-city-grants')
        {{-- City Grant Reminder Modal --}}
        <div class="modal modal-lg fade" id="grantReminderModal" tabindex="-1" aria-labelledby="grantReminderModalLabel"
             aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="grantReminderModalLabel">Send City Grant Reminders</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <form method="POST" action="{{ route('admin.grants.city.reminders') }}" id="grantReminderForm">
                            @csrf

                            @if ($errors->has('grant_ids') || $errors->has('message'))
                                <div class="alert alert-danger">
                                    @foreach ($errors->all() as $error)
                                        <div>{{ $error }}</div>
                                    @endforeach
                                </div>
                            @endif

                            <div class="mb-3">
                                <label class="form-label">City Grants to Check</label>
                                <div class="d-flex flex-wrap gap-2 mb-2">
                                    <button type="button" class="btn btn-sm btn-outline-secondary"
                                            onclick="setGrantReminderSelection(true)">
                                        Select All
                                    </button>
                                    <button type="button" class="btn btn-sm btn-outline-secondary"
                                            onclick="setGrantReminderSelection(false)">
                                        Select None
                                    </button>
                                </div>
                                <div class="row">
                                    @php
                                        $selectedGrantIds = collect(old('grant_ids', $grants->where('enabled', true)->pluck('id')->all()))
                                            ->map(fn ($id) => (int) $id)
                                            ->all();
                                    @endphp
                                    @foreach ($grants->sortBy('city_number') as $grant)
                                        <div class="col-md-6 mb-2">
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" name="grant_ids[]"
                                                       value="{{ $grant->id }}"
                                                       id="grant-reminder-{{ $grant->id }}"
                                                       @checked(in_array($grant->id, $selectedGrantIds, true))>
                                                <label class="form-check-label" for="grant-reminder-{{ $grant->id }}">
                                                    City #{{ $grant->city_number }}
                                                    @if (! $grant->enabled)
                                                        <span class="text-muted">(Disabled)</span>
                                                    @endif
                                                </label>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            </div>

                        <div class="mb-3">
                            <label class="form-label" for="grantReminderMessage">Admin Message</label>
                            <div class="border rounded p-2 mb-2 small text-muted">
                                <div>Hi {leader_name},</div>
                                <div class="mt-2">[Your message below]</div>
                                <div class="mt-2">
                                    Please click [link={link to apply for city grants}]here[/here] to apply for a city grant
                                </div>
                            </div>
                            <textarea class="form-control" rows="6" id="grantReminderMessage" name="message"
                                      required>{{ old('message', $defaultReminderMessage) }}</textarea>
                            <div class="form-text">
                                We will automatically add a greeting and the application link after this message.
                            </div>
                            </div>

                            <div class="modal-footer px-0">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                <button type="submit" class="btn btn-primary">Queue Reminders</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    @endcan
@endsection

@section("scripts")
    <script>
        function editGrant(grant) {
            document.getElementById('grantForm').action = `{{ url('admin/grants/city') }}/${grant.id}/update`;

            document.getElementById('grant_id').value = grant.id || '';
            document.getElementById('city_number').value = grant.city_number || '';
            document.getElementById('grant_amount').value = grant.grant_amount || '';
            document.getElementById('enabled').value = grant.enabled ? '1' : '0';
            document.getElementById('description').value = grant.description || '';

            let projectSelect = document.getElementById('projects');
            for (let option of projectSelect.options) {
                option.selected = grant.requirements?.required_projects?.includes(option.value) || false;
            }
        }

        function clearGrantForm() {
            document.getElementById('grantForm').action = `{{ url('admin/grants/city/create') }}`;

            document.getElementById('grantForm').reset();
            document.getElementById('grant_id').value = '';
            document.getElementById('grant_amount').value = 100;
        }

        function setGrantReminderSelection(isChecked) {
            const inputs = document.querySelectorAll('input[name="grant_ids[]"]');
            inputs.forEach((input) => {
                input.checked = isChecked;
            });
        }

        @can('manage-city-grants')
            @if ($errors->has('grant_ids') || $errors->has('message'))
            document.addEventListener('DOMContentLoaded', () => {
                const reminderModal = document.getElementById('grantReminderModal');
                if (reminderModal) {
                    const modal = new bootstrap.Modal(reminderModal);
                    modal.show();
                }
            });
            @endif
        @endcan
    </script>
@endsection
