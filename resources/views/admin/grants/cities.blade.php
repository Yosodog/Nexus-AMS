@php use App\Services\PWHelperService; @endphp
@extends('layouts.admin')

@section("content")
    <div class="app-content-header">
        <div class="container-fluid">
            <div class="row">
                <div class="col-sm-6">
                    <h3 class="mb-0">City Grant Management</h3>
                </div>
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
                        <td>${{ number_format($grant->grant_amount) }}</td>
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
                            <label for="grant_amount" class="form-label">Grant Amount</label>
                            <input type="number" class="form-control" name="grant_amount" id="grant_amount" required>
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
        }
    </script>
@endsection
