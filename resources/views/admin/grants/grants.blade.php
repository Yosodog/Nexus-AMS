@php use App\Services\PWHelperService; @endphp
@extends('layouts.admin')

@section("content")
    <div class="app-content-header">
        <div class="container-fluid">
            <div class="row">
                <div class="col-sm-6">
                    <h3 class="mb-0">Grant Management</h3>
                </div>
            </div>
        </div>
    </div>

    {{-- Info Boxes --}}
    <div class="row">
        <div class="col-md-3">
            <x-admin.info-box icon="bi bi-check-circle" bgColor="text-bg-primary" title="Total Approved"
                              :value="$totalApproved"/>
        </div>
        <div class="col-md-3">
            <x-admin.info-box icon="bi bi-x-circle" bgColor="text-bg-danger" title="Total Denied"
                              :value="$totalDenied"/>
        </div>
        <div class="col-md-3">
            <x-admin.info-box icon="bi bi-hourglass-split" bgColor="text-bg-warning" title="Pending"
                              :value="$pendingCount"/>
        </div>
        <div class="col-md-3">
            <x-admin.info-box icon="bi bi-cash" bgColor="text-bg-success" title="Total Funds Distributed"
                              :value="number_format($totalFundsDistributed)"/>
        </div>
    </div>

    {{-- Pending Grant Applications --}}
    <div class="card mt-4">
        <div class="card-header">Pending Applications</div>
        <div class="card-body">
            @if($pendingRequests->isEmpty())
                <p>No pending applications.</p>
            @else
                <table class="table table-striped">
                    <thead>
                    <tr>
                        <th>Grant</th>
                        <th>Nation</th>
                        <th>Account</th>
                        <th>Requested At</th>
                        <th>Actions</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($pendingRequests as $request)
                        <tr>
                            <td>{{ $request->grant->name }}</td>
                            <td>{{ $request->nation->nation_name }}</td>
                            <td>{{ $request->account->name }}</td>
                            <td>{{ $request->created_at->format('M d, Y') }}</td>
                            <td>
                                <form action="{{ route('admin.grants.approve', $request) }}" method="POST"
                                      class="d-inline">
                                    @csrf
                                    <button type="submit" class="btn btn-success btn-sm">Approve</button>
                                </form>
                                <form action="{{ route('admin.grants.deny', $request) }}" method="POST"
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

    {{-- Grants Table --}}
    <div class="card mt-4">
        <div class="card-header">Custom Grants</div>
        <div class="card-body">
            <table class="table table-striped">
                <thead>
                <tr>
                    <th>Name</th>
                    <th>Status</th>
                    <th>One-Time</th>
                    <th>Description</th>
                    <th>Resources</th>
                    <th>Actions</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($grants as $grant)
                    <tr>
                        <td>{{ $grant->name }}</td>
                        <td>{{ $grant->is_enabled ? 'Enabled' : 'Disabled' }}</td>
                        <td>{{ $grant->is_one_time ? 'Yes' : 'No' }}</td>
                        <td>{{ Str::limit($grant->description, 50) }}</td>
                        <td>
                            <button class="btn btn-sm btn-info" type="button"
                                    data-bs-toggle="collapse"
                                    data-bs-target="#resources-{{ $grant->id }}"
                                    aria-expanded="false"
                                    aria-controls="resources-{{ $grant->id }}">
                                View Resources
                            </button>
                        </td>
                        <td>
                            <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#grantModal"
                                    onclick="editGrant({{ json_encode($grant) }})">Edit
                            </button>
                        </td>
                    </tr>
                    <tr class="collapse" id="resources-{{ $grant->id }}">
                        <td colspan="6">
                            <div class="d-flex flex-wrap gap-2">
                                @foreach (PWHelperService::resources() as $res)
                                    @if ($grant->$res > 0)
                                        <span class="badge text-bg-light border">
                            <strong>{{ ucfirst($res) }}:</strong> {{ number_format($grant->$res) }}
                        </span>
                                    @endif
                                @endforeach
                            </div>
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
            <form id="grantForm" method="POST">
                @csrf
                <input type="hidden" name="id" id="grant_id">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="grantModalLabel">Manage Grant</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        {{-- Name --}}
                        <div class="mb-3">
                            <label class="form-label">Grant Name</label>
                            <input type="text" class="form-control" name="name" id="grant_name" required>
                        </div>

                        {{-- Description --}}
                        <div class="mb-3">
                            <label class="form-label">Description (Markdown Supported)</label>
                            <textarea class="form-control" name="description" id="grant_description"
                                      rows="3"></textarea>
                        </div>

                        {{-- Money --}}
                        <div class="mb-3">
                            <label class="form-label">Money</label>
                            <input type="number" class="form-control" name="money" id="grant_money" value="0" min="0">
                        </div>

                        {{-- Resources --}}
                        <div class="row">
                            @foreach (PWHelperService::resources(false) as $resource)
                                <div class="col-md-4 mb-3">
                                    <label class="form-label text-capitalize">{{ $resource }}</label>
                                    <input type="number" class="form-control" name="{{ $resource }}"
                                           id="grant_{{ $resource }}" value="0" min="0">
                                </div>
                            @endforeach
                        </div>

                        {{-- Status --}}
                        <div class="mb-3">
                            <label class="form-label">Status</label>
                            <select class="form-control" name="is_enabled" id="is_enabled">
                                <option value="1">Enabled</option>
                                <option value="0">Disabled</option>
                            </select>
                        </div>

                        {{-- One-Time --}}
                        <div class="form-check mb-3">
                            <input type="checkbox" class="form-check-input" id="is_one_time" name="is_one_time">
                            <label class="form-check-label" for="is_one_time">One-time grant</label>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="submit" class="btn btn-primary">Save Grant</button>
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
@endsection

@section("scripts")
    <script>
        function editGrant(grant) {
            document.getElementById('grantForm').action = `/admin/grants/${grant.id}/update`;

            document.getElementById('grant_id').value = grant.id || '';
            document.getElementById('grant_name').value = grant.name || '';
            document.getElementById('grant_description').value = grant.description || '';
            document.getElementById('is_enabled').value = grant.is_enabled ? '1' : '0';
            document.getElementById('is_one_time').checked = grant.is_one_time;

            document.getElementById('grant_money').value = grant.money || 0;

            ['coal', 'oil', 'uranium', 'iron', 'bauxite', 'lead', 'gasoline', 'munitions', 'steel', 'aluminum', 'food'].forEach(function (resource) {
                const input = document.getElementById(`grant_${resource}`);
                if (input) input.value = grant[resource] || 0;
            });
        }

        function clearGrantForm() {
            document.getElementById('grantForm').action = `/admin/grants/create`;
            document.getElementById('grantForm').reset();

            document.getElementById('grant_money').value = 0;

            ['coal', 'oil', 'uranium', 'iron', 'bauxite', 'lead', 'gasoline', 'munitions', 'steel', 'aluminum', 'food'].forEach(function (resource) {
                const input = document.getElementById(`grant_${resource}`);
                if (input) input.value = 0;
            });
        }
    </script>
@endsection