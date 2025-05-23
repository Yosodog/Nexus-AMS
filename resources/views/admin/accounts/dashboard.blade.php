@php use App\Services\PWHelperService; @endphp
@extends('layouts.admin')

@section("content")
    <div class="app-content-header">
        <div class="container-fluid">
            <div class="row">
                <div class="col-sm-6">
                    <h3 class="mb-0">Account Management</h3>
                </div>
            </div>
        </div>
    </div>

    {{-- Info Boxes --}}
    <div class="row mb-4">
        <div class="col-md-3">
            <x-admin.info-box icon="bi bi-person-circle" bgColor="text-bg-primary" title="Total Accounts"
                              :value="$accounts->count()"/>
        </div>
        <div class="col-md-3">
            <x-admin.info-box icon="bi bi-currency-dollar" bgColor="text-bg-success" title="Total Money"
                              :value="'$' . number_format($accounts->sum('money'), 2)"/>
        </div>
        <div class="col-md-3">
            <x-admin.info-box icon="bi bi-graph-up" bgColor="text-bg-warning" title="Average Balance"
                              :value="'$' . number_format($accounts->avg('money'), 2)"/>
        </div>
        <div class="col-md-3">
            <x-admin.info-box icon="bi bi-trophy" bgColor="text-bg-info" title="Top Account Balance"
                              :value="'$' . number_format($accounts->max('money'), 2)"/>
        </div>
    </div>

    {{-- Accounts Table --}}
    <div class="card shadow-sm">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0">All Accounts</h5>
            <div class="card-tools">
                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="location.reload()">
                    <i class="bi bi-arrow-clockwise"></i> Refresh
                </button>
            </div>
        </div>

        <div class="card-body p-3 table-responsive">
            <table id="account_table" class="table table-hover text-nowrap align-middle mb-0">
                <thead class="table-light">
                <tr>
                    <th>Nation</th>
                    <th>Owner</th>
                    <th>Name</th>
                    <th>Money</th>
                    @foreach(PWHelperService::resources(false) as $resource)
                        <th>{{ ucfirst($resource) }}</th>
                    @endforeach
                </tr>
                </thead>
                <tbody>
                @foreach ($accounts as $acc)
                    <tr>
                        <td>
                            <a href="https://politicsandwar.com/nation/id={{ $acc->nation_id }}" target="_blank">
                                {{ $acc->nation_id }}
                            </a>
                        </td>
                        <td>
                            @if($acc->user)
                                {{ $acc->user->name }}
                            @else
                                <span class="text-muted"><i class="bi bi-person-x"></i> Deleted</span>
                            @endif
                        </td>
                        <td>
                            <a href="{{ route('admin.accounts.view', $acc->id) }}">
                                {{ $acc->name }}
                            </a>
                        </td>
                        <td>${{ number_format($acc->money, 2) }}</td>
                        @foreach(PWHelperService::resources(false) as $resource)
                            <td>{{ number_format($acc->$resource, 2) }}</td>
                        @endforeach
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>

    @include('admin.accounts.direct_deposit')
@endsection

@section("scripts")
    <script>
        $(function () {
            $('#account_table').DataTable({
                responsive: true,
                pageLength: 25,
                ordering: true,
                language: {
                    searchPlaceholder: "Search accounts..."
                },
                columnDefs: [
                    {targets: "_all", className: "align-middle"}
                ]
            });
        });
    </script>
@endsection