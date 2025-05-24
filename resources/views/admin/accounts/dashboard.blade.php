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

    {{-- Recent Transactions --}}
    <div class="card mt-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0">Recent Transactions (Last 50)</h5>
        </div>

        <div class="card-body p-3 table-responsive">
            <table class="table table-hover text-nowrap align-middle" id="recent_transactions_table">
                <thead class="table-light">
                <tr>
                    <th>Date</th>
                    <th>From</th>
                    <th>To</th>
                    <th>Type</th>
                    @foreach(PWHelperService::resources() as $resource)
                        <th>{{ ucfirst($resource) }}</th>
                    @endforeach
                    <th>Action</th>
                </tr>
                </thead>
                <tbody>
                @foreach($recentTransactions as $transaction)
                    <tr>
                        <td>{{ $transaction->created_at->format('Y-m-d H:i') }}</td>
                        <td>
                            @if($transaction->fromAccount)
                                <a href="{{ route('admin.accounts.view', $transaction->fromAccount->id) }}">
                                    {{ $transaction->fromAccount->name }}
                                </a>
                            @elseif($transaction->nation_id && $transaction->transaction_type === 'deposit')
                                Nation #{{ $transaction->nation_id }}
                            @else
                                N/A
                            @endif
                        </td>
                        <td>
                            @if($transaction->toAccount)
                                <a href="{{ route('admin.accounts.view', $transaction->toAccount->id) }}">
                                    {{ $transaction->toAccount->name }}
                                </a>
                            @elseif($transaction->nation_id)
                                Nation #{{ $transaction->nation_id }}
                            @else
                                N/A
                            @endif
                        </td>
                        <td>{{ ucfirst($transaction->transaction_type) }}</td>
                        <td>${{ number_format($transaction->money, 2) }}</td>
                        @foreach(PWHelperService::resources(false) as $resource)
                            <td>{{ number_format($transaction->$resource, 2) }}</td>
                        @endforeach
                        <td>
                            @if($transaction->isNationWithdrawal() && !$transaction->isRefunded() && Gate::allows('manage-accounts'))
                                <form method="POST"
                                      action="{{ route('admin.accounts.transactions.refund', $transaction) }}"
                                      onsubmit="return confirm('Are you sure you want to refund this transaction?');">
                                    @csrf
                                    <button class="btn btn-sm btn-outline-danger">Refund</button>
                                </form>
                            @elseif($transaction->isRefunded())
                                <span class="badge bg-secondary">Refunded</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>

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