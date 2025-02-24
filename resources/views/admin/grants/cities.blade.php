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
            <x-admin.info-box icon="bi bi-check-circle" bgColor="text-bg-primary" title="Total Approved Grants" :value="$totalApproved" />
        </div>
        <div class="col-md-3">
            <x-admin.info-box icon="bi bi-x-circle" bgColor="text-bg-danger" title="Total Denied Grants" :value="$totalDenied" />
        </div>
        <div class="col-md-3">
            <x-admin.info-box icon="bi bi-hourglass-split" bgColor="text-bg-warning" title="Pending Grants" :value="$pendingCount" />
        </div>
        <div class="col-md-3">
            <x-admin.info-box icon="bi bi-cash" bgColor="text-bg-success" title="Total Funds Distributed" :value="number_format($totalFundsDistributed)" />
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
                                <form action="{{ route('admin.grants.city.approve', $request) }}" method="POST" class="d-inline">
                                    @csrf
                                    <button type="submit" class="btn btn-success btn-sm">Approve</button>
                                </form>
                                <form action="{{ route('admin.grants.city.deny', $request) }}" method="POST" class="d-inline">
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
                    <th>Name</th>
                    <th>Amount</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($grants as $grant)
                    <tr>
                        <td>{{ $grant->name }}</td>
                        <td>${{ number_format($grant->grant_amount) }}</td>
                        <td>{{ $grant->enabled ? 'Enabled' : 'Disabled' }}</td>
                        <td>
                            <a href="{{ route('admin.grants.city', $grant->id) }}"
                               class="btn btn-primary btn-sm">Edit</a>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endsection
