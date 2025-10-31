@php
    use App\Services\PWHelperService;

    $resourceList = PWHelperService::resources(false);
    $resourceTotals = collect($resourceList)
        ->mapWithKeys(fn ($resource) => [$resource => $accounts->sum($resource)]);
    $topAccounts = $accounts->sortByDesc('money')->take(5);
    $activeAccounts = $accounts->filter(fn ($account) => $account->user)->count();
    $inactiveAccounts = $accounts->count() - $activeAccounts;
    $averageTransactionsPerDay = $recentTransactions
        ->groupBy(fn ($transaction) => $transaction->created_at->format('Y-m-d'))
        ->map->count()
        ->avg() ?? 0;
@endphp

@extends('layouts.admin')

@section("content")
    <div class="app-content-header border-0 pb-0">
        <div class="container-fluid">
            <div class="row align-items-center">
                <div class="col-sm-6">
                    <h3 class="mb-1">Account Management</h3>
                    <p class="text-muted mb-0 small">Monitor alliance bank performance, approve withdrawals, and review direct deposits at a glance.</p>
                </div>
                <div class="col-sm-6 text-sm-end mt-3 mt-sm-0">
                    <a href="#direct-deposit" class="btn btn-outline-primary btn-sm">
                        <i class="bi bi-bank me-2"></i>Direct Deposit Hub
                    </a>
                    <button type="button" class="btn btn-light btn-sm ms-sm-2" onclick="location.reload()">
                        <i class="bi bi-arrow-clockwise me-2"></i>Refresh Data
                    </button>
                </div>
            </div>
        </div>
    </div>

    {{-- Overview --}}
    <div class="row g-3 mb-4">
        <div class="col-md-6 col-xl-3">
            <div class="card border-0 shadow-sm h-100 bg-primary text-white bg-gradient">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start mb-3">
                        <div>
                            <span class="text-uppercase fw-semibold text-white-50 small">Total Accounts</span>
                            <h2 class="fw-bold mb-0">{{ number_format($accounts->count()) }}</h2>
                        </div>
                        <span class="badge text-bg-light text-primary-emphasis">
                            <i class="bi bi-people"></i>
                        </span>
                    </div>
                    <div class="d-flex flex-wrap gap-3 small text-white-50">
                        <span><i class="bi bi-person-check me-1"></i>{{ number_format($activeAccounts) }} assigned</span>
                        <span><i class="bi bi-person-dash me-1"></i>{{ number_format($inactiveAccounts) }} unassigned</span>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-xl-3">
            <div class="card border-0 shadow-sm h-100 bg-success text-white bg-gradient">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start mb-3">
                        <div>
                            <span class="text-uppercase fw-semibold text-white-50 small">Total Holdings</span>
                            <h2 class="fw-bold mb-0">${{ number_format($accounts->sum('money'), 2) }}</h2>
                        </div>
                        <span class="badge text-bg-light text-success-emphasis">
                            <i class="bi bi-currency-dollar"></i>
                        </span>
                    </div>
                    <p class="mb-0 small text-white-50">Across {{ $resourceTotals->count() }} tracked resources in the bank.</p>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-xl-3">
            <div class="card border-0 shadow-sm h-100 bg-warning text-dark bg-gradient">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start mb-3">
                        <div>
                            <span class="text-uppercase fw-semibold text-dark-50 small">Average Balance</span>
                            <h2 class="fw-bold mb-0">${{ number_format($accounts->avg('money'), 2) }}</h2>
                        </div>
                        <span class="badge text-bg-light text-success-emphasis">
                            <i class="bi bi-graph-up"></i>
                        </span>
                    </div>
                    <p class="mb-0 small text-muted">{{ number_format($averageTransactionsPerDay, 1) }} transactions/day over the last 50 records.</p>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-xl-3">
            <div class="card border-0 shadow-sm h-100 bg-info text-dark bg-gradient">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start mb-3">
                        <div>
                            <span class="text-uppercase fw-semibold text-dark-50 small">Top Account</span>
                            <h2 class="fw-bold mb-0">${{ number_format($accounts->max('money'), 2) }}</h2>
                        </div>
                        <span class="badge text-bg-light text-success-emphasis">
                            <i class="bi bi-trophy"></i>
                        </span>
                    </div>
                    @if($topAccounts->isNotEmpty())
                        <p class="mb-0 small text-muted">{{ $topAccounts->first()->name }} (Nation #{{ $topAccounts->first()->nation_id }})</p>
                    @else
                        <p class="mb-0 small text-muted">No accounts available.</p>
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- Accounts Table --}}
    <div class="card shadow-sm border-0">
        <div class="card-header d-flex flex-column flex-lg-row gap-3 justify-content-between align-items-lg-center">
            <div>
                <h5 class="mb-1">All Accounts</h5>
                <p class="mb-0 text-muted small">Search, sort, and drill down into every managed bank account.</p>
            </div>
            <div class="d-flex flex-wrap align-items-center gap-2">
                <span class="badge text-bg-secondary">{{ number_format($accounts->count()) }} accounts</span>
                <span class="badge text-bg-light text-secondary-emphasis">Avg balance ${{ number_format($accounts->avg('money'), 2) }}</span>
            </div>
        </div>

        <div class="card-body p-0">
            <div class="table-responsive">
                <table id="account_table" class="table table-hover table-striped align-middle mb-0 w-100">
                    <thead class="table-light">
                    <tr>
                        <th>Owner</th>
                        <th>Name</th>
                        <th class="text-end">Money</th>
                        @foreach($resourceList as $resource)
                            <th class="text-end">{{ ucfirst($resource) }}</th>
                        @endforeach
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($accounts as $acc)
                        <tr>
                            <td>
                                @if($acc->user)
                                    <span class="fw-semibold"><a href="https://politicsandwar.com/nation/id={{ $acc->nation_id }}" target="_blank" rel="noopener" class="text-decoration-none">{{ $acc->user->name }}</a></span>
                                @else
                                    <span class="text-muted"><i class="bi bi-person-x me-1"></i>Deleted</span>
                                @endif
                            </td>
                            <td>
                                <a href="{{ route('admin.accounts.view', $acc->id) }}" class="link-primary fw-semibold">
                                    {{ $acc->name }}
                                </a>
                            </td>
                            <td class="text-end" data-order="{{ $acc->money }}">${{ number_format($acc->money, 2) }}</td>
                            @foreach($resourceList as $resource)
                                <td class="text-end" data-order="{{ $acc->$resource }}">{{ number_format($acc->$resource, 2) }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="row g-3 mt-4 align-items-stretch">
        @can('manage-accounts')
            <div class="col-12 col-xxl-8">
                <div class="card shadow-sm border-0 h-100">
                    <div class="card-header d-flex flex-column flex-lg-row justify-content-between gap-2 align-items-lg-center">
                        <div>
                            <h5 class="mb-1">Automatic Withdrawal Limits</h5>
                            <p class="mb-0 text-muted small">Fine-tune automatic approvals across money and resource types.</p>
                        </div>
                        <span class="badge text-bg-info text-uppercase">Controls</span>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="{{ route('admin.withdrawals.limits') }}" class="row g-4">
                            @csrf
                            <div class="col-12 col-lg-6">
                                <label for="max_daily_withdrawals" class="form-label fw-semibold">Maximum Automatic Withdrawals Per Day</label>
                                <input type="number" min="0" class="form-control" id="max_daily_withdrawals"
                                       name="max_daily_withdrawals" value="{{ old('max_daily_withdrawals', $maxDailyWithdrawals) }}"
                                       required>
                                <div class="form-text">Set to 0 to allow unlimited automatic approvals.</div>
                            </div>

                            <div class="col-12 col-lg-6">
                                <div class="card bg-light border-0 h-100">
                                    <div class="card-body">
                                        <p class="mb-2 text-muted text-uppercase small">At-a-glance</p>
                                        <div class="d-flex flex-column gap-2">
                                            <div class="d-flex justify-content-between">
                                                <span class="text-muted small">Pending withdrawals</span>
                                                <span class="fw-semibold">{{ number_format($pendingWithdrawals->count()) }}</span>
                                            </div>
                                            <div class="d-flex justify-content-between">
                                                <span class="text-muted small">Daily auto limit</span>
                                                <span class="fw-semibold">{{ $maxDailyWithdrawals > 0 ? number_format($maxDailyWithdrawals) : 'Unlimited' }}</span>
                                            </div>
                                            <div class="d-flex justify-content-between">
                                                <span class="text-muted small">Resources with limits</span>
                                                <span class="fw-semibold">{{ number_format($withdrawalLimits->filter(fn($limit) => (float) $limit->daily_limit > 0)->count()) }}</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="col-12">
                                <div class="table-responsive rounded border">
                                    <table class="table table-sm align-middle mb-0">
                                        <thead class="table-light">
                                        <tr>
                                            <th>Resource</th>
                                            <th>Daily Auto-Approval Limit</th>
                                        </tr>
                                        </thead>
                                        <tbody>
                                        @foreach(PWHelperService::resources() as $resource)
                                            @php $limit = optional($withdrawalLimits->get($resource))->daily_limit ?? 0 @endphp
                                            <tr>
                                                <td class="text-capitalize">{{ $resource }}</td>
                                                <td>
                                                    <div class="input-group">
                                                        <span class="input-group-text">{{ $resource === 'money' ? '$' : '' }}</span>
                                                        <input type="number" step="0.01" min="0" class="form-control"
                                                               name="limits[{{ $resource }}]"
                                                               value="{{ old('limits.' . $resource, $limit) }}">
                                                    </div>
                                                </td>
                                            </tr>
                                        @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                            <div class="col-12 d-flex justify-content-end">
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-save me-2"></i>Save Limits
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        @endcan

        <div class="col-12 col-xxl-4">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-header">
                    <h5 class="mb-1">Insights</h5>
                    <p class="mb-0 text-muted small">High-impact accounts and aggregate resource positions.</p>
                </div>
                <div class="card-body d-flex flex-column gap-4">
                    <div>
                        <h6 class="text-uppercase text-muted small mb-2">Top Balances</h6>
                        <ul class="list-group list-group-flush">
                            @forelse($topAccounts as $account)
                                <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                                    <div>
                                        <a href="{{ route('admin.accounts.view', $account->id) }}" class="fw-semibold text-decoration-none">
                                            {{ $account->name }}
                                        </a>
                                        <div class="small text-muted">Nation #{{ $account->nation_id }}</div>
                                    </div>
                                    <span class="fw-semibold">${{ number_format($account->money, 2) }}</span>
                                </li>
                            @empty
                                <li class="list-group-item px-0 text-muted">No accounts available.</li>
                            @endforelse
                        </ul>
                    </div>

                    <div>
                        <h6 class="text-uppercase text-muted small mb-2">Resource Stockpile</h6>
                        <div class="table-responsive">
                            <table class="table table-sm table-borderless mb-0">
                                <tbody>
                                @foreach($resourceTotals as $resource => $total)
                                    <tr>
                                        <td class="text-capitalize text-muted">{{ $resource }}</td>
                                        <td class="text-end fw-semibold">{{ number_format($total, 2) }}</td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div>
                        <h6 class="text-uppercase text-muted small mb-2">Engagement</h6>
                        <p class="mb-1 small text-muted">{{ number_format($activeAccounts) }} accounts are assigned to members and {{ number_format($inactiveAccounts) }} remain unassigned.</p>
                        <p class="mb-0 small text-muted">Average of {{ number_format($averageTransactionsPerDay, 1) }} transactions processed daily.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    @can('manage-accounts')
        <div class="row g-3 mt-4">
            <div class="col-md-4">
                <div class="card shadow-sm border-0 h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <span class="text-uppercase text-muted small">Pending Withdrawals</span>
                                <h3 class="fw-bold mb-0">{{ number_format($pendingWithdrawals->count()) }}</h3>
                            </div>
                            <span class="badge text-bg-warning"><i class="bi bi-hourglass-split"></i></span>
                        </div>
                        <p class="mb-0 small text-muted">Awaiting manual review before funds leave the bank.</p>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card shadow-sm border-0 h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <span class="text-uppercase text-muted small">Daily Auto Limit</span>
                                <h3 class="fw-bold mb-0">{{ $maxDailyWithdrawals > 0 ? number_format($maxDailyWithdrawals) : 'Unlimited' }}</h3>
                            </div>
                            <span class="badge text-bg-info"><i class="bi bi-arrow-repeat"></i></span>
                        </div>
                        <p class="mb-0 small text-muted">Automatic approvals of transactions in the last 24 hours.</p>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card shadow-sm border-0 h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <span class="text-uppercase text-muted small">Resources With Limits</span>
                                <h3 class="fw-bold mb-0">{{ number_format($withdrawalLimits->filter(fn($limit) => (float) $limit->daily_limit > 0)->count()) }}</h3>
                            </div>
                            <span class="badge text-bg-success"><i class="bi bi-shield-lock"></i></span>
                        </div>
                        <p class="mb-0 small text-muted">Resources with an automatic approval ceiling configured.</p>
                    </div>
                </div>
            </div>
        </div>

        @if($pendingWithdrawals->isNotEmpty())
            <div class="card mt-4 shadow-sm border-0">
                <div class="card-header d-flex flex-column flex-md-row justify-content-between align-items-md-center">
                    <div>
                        <h5 class="mb-1">Pending Withdrawal Approvals</h5>
                        <p class="mb-0 text-muted small">Review and action outstanding requests submitted by members.</p>
                    </div>
                    <span class="badge text-bg-warning">{{ number_format($pendingWithdrawals->count()) }} pending</span>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped align-middle">
                            <thead>
                            <tr>
                                <th>Requested</th>
                                <th>Member</th>
                                <th>From Account</th>
                                <th>Nation</th>
                                <th>Resources</th>
                                <th>Reason</th>
                                <th class="text-end">Actions</th>
                            </tr>
                            </thead>
                            <tbody>
                            @foreach($pendingWithdrawals as $transaction)
                                <tr>
                                    <td>{{ $transaction->created_at?->format('M d, Y H:i') }}</td>
                                    <td>{{ $transaction->fromAccount?->user?->name ?? 'Unknown User' }}</td>
                                    <td>{{ $transaction->fromAccount?->name ?? 'Unknown Account' }}</td>
                                    <td>{{ $transaction->nation?->nation_name ?? 'Unknown Nation' }}</td>
                                    <td>
                                        <ul class="mb-0 ps-3">
                                            @foreach(PWHelperService::resources() as $resource)
                                                @php $amount = $transaction->{$resource} @endphp
                                                @if($amount > 0)
                                                    <li>
                                                        {{ ucfirst($resource) }}: {{ $resource === 'money' ? '$' : '' }}{{ number_format($amount, 2) }}
                                                    </li>
                                                @endif
                                            @endforeach
                                        </ul>
                                    </td>
                                    <td>{{ $transaction->pending_reason ?? 'Manual approval required' }}</td>
                                    <td class="text-end">
                                        <form action="{{ route('admin.withdrawals.approve', $transaction) }}" method="POST"
                                              class="d-inline">
                                            @csrf
                                            <button type="submit" class="btn btn-success btn-sm">
                                                <i class="bi bi-check-circle me-1"></i>Approve
                                            </button>
                                        </form>
                                        <button class="btn btn-outline-danger btn-sm" type="button"
                                                data-bs-toggle="collapse"
                                                data-bs-target="#deny-form-{{ $transaction->id }}"
                                                aria-expanded="false" aria-controls="deny-form-{{ $transaction->id }}">
                                            Deny
                                        </button>
                                        <div class="collapse mt-2" id="deny-form-{{ $transaction->id }}">
                                            <form action="{{ route('admin.withdrawals.deny', $transaction) }}" method="POST">
                                                @csrf
                                                <div class="mb-2">
                                                    <label for="deny-reason-{{ $transaction->id }}" class="form-label">Reason</label>
                                                    <textarea class="form-control" name="reason" id="deny-reason-{{ $transaction->id }}"
                                                              rows="2" maxlength="500" required></textarea>
                                                </div>
                                                <button type="submit" class="btn btn-danger btn-sm">
                                                    <i class="bi bi-x-circle me-1"></i>Confirm Denial
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        @endif
    @endcan

    <div id="direct-deposit" class="mt-4">
        @include('admin.accounts.direct_deposit')
    </div>

    {{-- Recent Transactions --}}
    <div class="card mt-4 shadow-sm border-0">
        <div class="card-header d-flex flex-column flex-md-row justify-content-between align-items-md-center">
            <div>
                <h5 class="mb-1">Recent Transactions (Last 50)</h5>
                <p class="mb-0 text-muted small">Track deposits, transfers, and withdrawals as they happen.</p>
            </div>
        </div>

        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover text-nowrap align-middle mb-0" id="recent_transactions_table">
                    <thead class="table-light">
                    <tr>
                        <th>Date</th>
                        <th>From</th>
                        <th>To</th>
                        <th>Type</th>
                        <th class="text-end">Money</th>
                        @foreach(PWHelperService::resources(false) as $resource)
                            <th class="text-end">{{ ucfirst($resource) }}</th>
                        @endforeach
                        <th class="text-end">Action</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($recentTransactions as $transaction)
                        <tr>
                            <td>{{ $transaction->created_at->format('Y-m-d H:i') }}</td>
                            <td>
                                @if($transaction->fromAccount)
                                    <a href="{{ route('admin.accounts.view', $transaction->fromAccount->id) }}" class="text-decoration-none">
                                        {{ $transaction->fromAccount->name }}
                                    </a>
                                @elseif($transaction->nation_id && $transaction->transaction_type === 'deposit')
                                    Nation #{{ $transaction->nation_id }}
                                @else
                                    <span class="text-muted">N/A</span>
                                @endif
                            </td>
                            <td>
                                @if($transaction->toAccount)
                                    <a href="{{ route('admin.accounts.view', $transaction->toAccount->id) }}" class="text-decoration-none">
                                        {{ $transaction->toAccount->name }}
                                    </a>
                                @elseif($transaction->nation_id)
                                    Nation #{{ $transaction->nation_id }}
                                @else
                                    <span class="text-muted">N/A</span>
                                @endif
                            </td>
                            <td>{{ ucfirst($transaction->transaction_type) }}</td>
                            <td class="text-end">${{ number_format($transaction->money, 2) }}</td>
                            @foreach(PWHelperService::resources(false) as $resource)
                                <td class="text-end">{{ number_format($transaction->$resource, 2) }}</td>
                            @endforeach
                            <td class="text-end">
                                @if($transaction->isNationWithdrawal() && !$transaction->isRefunded() && Gate::allows('manage-accounts'))
                                    <form method="POST"
                                          action="{{ route('admin.accounts.transactions.refund', $transaction) }}"
                                          onsubmit="return confirm('Are you sure you want to refund this transaction?');">
                                        @csrf
                                        <button class="btn btn-sm btn-outline-danger">
                                            <i class="bi bi-arrow-counterclockwise me-1"></i>Refund
                                        </button>
                                    </form>
                                @elseif($transaction->isRefunded())
                                    <span class="badge text-bg-secondary">Refunded</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection

@push("scripts")
    <script>
        $(function () {
            $('#account_table').DataTable({
                pageLength: 25,
                lengthMenu: [[25, 50, 100, -1], [25, 50, 100, 'All']],
                order: [[2, 'desc']],
                scrollX: true,
                autoWidth: false,
                language: {
                    searchPlaceholder: 'Search accounts...',
                    search: ''
                },
                dom: '<"px-3 pt-3 pb-2"lf>t<"px-3 pb-3"ip>',
                columnDefs: [
                    {targets: '_all', className: 'align-middle'}
                ]
            });
        });
    </script>
@endpush
