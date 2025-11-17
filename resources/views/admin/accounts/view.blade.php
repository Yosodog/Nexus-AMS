@php use App\Services\AccountService;use App\Services\PWHelperService; @endphp
@extends('layouts.admin')

@section("content")
    <div class="app-content-header">
        <div class="container-fluid">
            <div class="row">
                <div class="col-sm-6">
                    <h3 class="mb-0">View Account - {{ $account->name }}</h3>
                </div>
            </div>
        </div>
    </div>

    <div class="app-content">
        <div class="container-fluid">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Balance</h3>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                            <tr>
                                @foreach(PWHelperService::resources() as $resource)
                                    <th>{{ ucfirst($resource) }}</th>
                                @endforeach
                            </tr>
                            </thead>
                            <tbody>
                            <tr>
                                <td>${{ number_format($account->money, 2) }}</td>
                                @foreach(PWHelperService::resources(false) as $resource)
                                    <td>{{ number_format($account->$resource, 2) }}</td>
                                @endforeach
                            </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <hr>

            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Manual Adjustment</h3>
                </div>
                <div class="card-body">
                    <form action="{{ route('admin.accounts.adjust', $account->id) }}" method="POST">
                        @csrf
                        <div class="row">
                            @foreach (PWHelperService::resources() as $resource)
                                <div class="col-md-3">
                                    <label for="{{ $resource }}">{{ ucfirst($resource) }}</label>
                                    <input type="number" name="{{ $resource }}" id="{{ $resource }}"
                                           class="form-control" step="0.01" placeholder="0">
                                </div>
                            @endforeach
                        </div>
                        <div class="mt-3">
                            <label for="note">Note (Reason for Adjustment)</label>
                            <input type="text" name="note" id="note" class="form-control" required>
                        </div>
                        <input type="hidden" name="accountId" value="{{ $account->id }}">
                        <button type="submit" class="btn btn-primary mt-3">Adjust Balance</button>
                    </form>
                </div>
            </div>

            <hr>

            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Last 500 Transactions</h3>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover" id="transaction_table">
                            <thead>
                            <tr>
                                <th>Date</th>
                                <th>From Account</th>
                                <th>To Account</th>
                                <th>Type</th>
                                @foreach(PWHelperService::resources() as $resource)
                                    <th>{{ ucfirst($resource) }}</th>
                                @endforeach
                                <th>Action</th>
                            </tr>
                            </thead>
                            <tbody>
                            @foreach ($transactions as $transaction)
                                <tr class="hover">
                                    <td>{{ $transaction->created_at->format('Y-m-d H:i') }}</td>
                                    {{-- From Account --}}
                                    <td>
                                        @if($transaction->transaction_type === 'deposit' && $transaction->nation_id)
                                            @if($transaction->nation)
                                                <a href="https://politicsandwar.com/nation/id={{ $transaction->nation_id }}"
                                                   class="link link-primary" target="_blank">
                                                    {{ $transaction->nation->nation_name }}
                                                </a>
                                            @else
                                                Nation #{{ $transaction->nation_id }}
                                            @endif
                                        @elseif($transaction->fromAccount)
                                            <a href="{{ route('admin.accounts.view', $transaction->fromAccount->id) }}"
                                               class="link link-primary">
                                                {{ $transaction->fromAccount->name }}
                                            </a>
                                        @else
                                            N/A
                                        @endif
                                    </td>

                                    {{-- To Account --}}
                                    <td>
                                        @if($transaction->toAccount)
                                            <a href="{{ route('admin.accounts.view', $transaction->toAccount->id) }}"
                                               class="link link-primary">
                                                {{ $transaction->toAccount->name }}
                                            </a>
                                        @elseif($transaction->nation_id)
                                            @if($transaction->nation)
                                                <a href="https://politicsandwar.com/nation/id={{ $transaction->nation_id }}"
                                                   class="link link-primary" target="_blank">
                                                    {{ $transaction->nation->nation_name }}
                                                </a>
                                            @else
                                                Nation #{{ $transaction->nation_id }}
                                            @endif
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
                                            <span class="badge bg-secondary" data-bs-toggle="tooltip"
                                                  title="This transaction was refunded.">Refunded</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <hr>

            <div class="card mt-4">
                <div class="card-header">
                    <h3 class="card-title">Last 500 Manual Adjustments</h3>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover" id="manual_transactions_table">
                            <thead>
                            <tr>
                                <th>Date</th>
                                <th>Admin</th>
                                @foreach(PWHelperService::resources() as $resource)
                                    <th>{{ ucfirst($resource) }}</th>
                                @endforeach
                                <th>Note</th>
                            </tr>
                            </thead>
                            <tbody>
                            @foreach ($manualTransactions as $transaction)
                                <tr>
                                    <td>{{ $transaction->created_at->format('Y-m-d H:i') }}</td>
                                    <td>
                                        @if($transaction->admin)
                                            {{ $transaction->admin->name }}
                                        @else
                                            <span class="text-danger">Admin #{{ $transaction->admin_id }} (Deleted)</span>
                                        @endif
                                    </td>
                                    <td>${{ number_format($transaction->money, 2) }}</td>
                                    @foreach(PWHelperService::resources(false) as $resource)
                                        <td>{{ number_format($transaction->$resource, 2) }}</td>
                                    @endforeach
                                    <td>
                                        <button type="button" class="btn btn-sm btn-outline-info"
                                                data-bs-toggle="popover" data-bs-placement="top" title="Note"
                                                data-bs-content="{{ $transaction->note }}">
                                            View Note
                                        </button>
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            {{-- Direct Deposit Logs --}}
            <div id="direct-deposit-logs" class="card mt-4">
                <div class="card-header d-flex flex-column flex-md-row justify-content-between align-items-md-center">
                    <div>
                        <h5 class="mb-1 d-flex align-items-center gap-2">
                            <span class="badge text-bg-primary">DD</span>
                            Direct Deposit Logs
                        </h5>
                        <p class="mb-0 text-muted small">After-tax payouts tagged to this account.</p>
                    </div>
                    <a href="#mmr-assistant" class="text-decoration-none small fw-semibold ms-md-auto">Jump to MMR Assistant <i class="bi bi-arrow-down-right ms-1"></i></a>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover text-nowrap align-middle mb-0">
                            <thead class="table-light">
                            <tr>
                                <th>Date</th>
                                <th>Nation</th>
                                <th class="text-end">Cash Paid</th>
                                <th>Resources Delivered</th>
                            </tr>
                            </thead>
                            <tbody>
                            @forelse($directDepositLogs as $log)
                                @php
                                    $deliveredResources = collect(PWHelperService::resources(false))
                                        ->filter(fn ($res) => (float) $log->$res > 0)
                                        ->mapWithKeys(fn ($res) => [$res => $log->$res]);
                                @endphp
                                <tr>
                                    <td>{{ $log->created_at?->format('Y-m-d H:i') ?? '—' }}</td>
                                    <td>
                                        <a href="https://politicsandwar.com/nation/id={{ $log->nation_id }}" target="_blank" class="text-decoration-none">
                                            Nation #{{ $log->nation_id }}
                                        </a>
                                    </td>
                                    <td class="text-end">${{ number_format((float) $log->money, 2) }}</td>
                                    <td>
                                        @if($deliveredResources->isNotEmpty())
                                            <div class="d-flex flex-wrap gap-2">
                                                @foreach($deliveredResources as $resource => $amount)
                                                    <span class="badge text-bg-light text-secondary border">
                                                        {{ ucfirst($resource) }}: {{ number_format((float) $amount, 2) }}
                                                    </span>
                                                @endforeach
                                            </div>
                                        @else
                                            <span class="text-muted">Money only</span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="text-center text-muted py-4">No direct deposit activity for this account.</td>
                                </tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="card-footer bg-light">
                    <div class="d-flex flex-column flex-lg-row align-items-lg-center w-100 gap-2">
                        <div class="small text-muted">Showing {{ $directDepositLogs->count() }} of {{ $directDepositLogs->total() }} entries</div>
                        <div class="ms-lg-auto">
                            {{ $directDepositLogs->withQueryString()->links('vendor.pagination.bootstrap-5') }}
                        </div>
                    </div>
                </div>
            </div>

            {{-- MMR Assistant Purchases --}}
            <div id="mmr-assistant" class="card mt-4">
                <div class="card-header d-flex flex-column flex-md-row justify-content-between align-items-md-center">
                    <div>
                        <h5 class="mb-1 d-flex align-items-center gap-2">
                            <span class="badge text-bg-dark">MMR</span>
                            MMR Assistant Purchases
                        </h5>
                        <p class="mb-0 text-muted small">Withheld cash converted into resources via MMR Assistant.</p>
                    </div>
                    <a href="#direct-deposit-logs" class="text-decoration-none small fw-semibold ms-md-auto">Back to DD logs <i class="bi bi-arrow-up-left ms-1"></i></a>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover text-nowrap align-middle mb-0">
                            <thead class="table-light">
                            <tr>
                                <th>Date</th>
                                <th class="text-end">Total Spent</th>
                                <th>Resources Purchased</th>
                            </tr>
                            </thead>
                            <tbody>
                            @forelse($mmrPurchases as $purchase)
                                @php
                                    $purchasedResources = collect(PWHelperService::resources(false))
                                        ->filter(fn ($res) => (float) $purchase->$res > 0)
                                        ->mapWithKeys(fn ($res) => [$res => [
                                            'qty' => $purchase->$res,
                                            'ppu' => $purchase->getAttribute("{$res}_ppu"),
                                        ]]);
                                @endphp
                                <tr>
                                    <td>{{ $purchase->created_at?->format('Y-m-d H:i') ?? '—' }}</td>
                                    <td class="text-end">${{ number_format((float) $purchase->total_spent, 2) }}</td>
                                    <td>
                                        @if($purchasedResources->isNotEmpty())
                                            <div class="d-flex flex-wrap gap-2">
                                                @foreach($purchasedResources as $resource => $data)
                                                    <span class="badge text-bg-light text-secondary border">
                                                        {{ ucfirst($resource) }}: {{ number_format((float) $data['qty'], 2) }}
                                                        @if($data['ppu'])
                                                            <span class="text-muted"> @ ${{ number_format((float) $data['ppu'], 2) }}</span>
                                                        @endif
                                                    </span>
                                                @endforeach
                                            </div>
                                        @else
                                            <span class="text-muted">No resources purchased</span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="3" class="text-center text-muted py-4">No MMR Assistant purchases for this account.</td>
                                </tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="card-footer bg-light">
                    <div class="d-flex flex-column flex-lg-row align-items-lg-center w-100 gap-2">
                        <div class="small text-muted">Showing {{ $mmrPurchases->count() }} of {{ $mmrPurchases->total() }} purchases</div>
                        <div class="ms-lg-auto">
                            {{ $mmrPurchases->withQueryString()->links('vendor.pagination.bootstrap-5') }}
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>
@endsection

@section("scripts")
    <script>
        $(document).ready(function () {
            $('#transaction_table').DataTable({
                "order": [[0, "desc"]]
            });

            $('#manual_transactions_table').DataTable({
                "order": [[0, "desc"]]
            });

            // Enable Bootstrap Popovers
            var popoverTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="popover"]'));
            popoverTriggerList.map(function (popoverTriggerEl) {
                return new bootstrap.Popover(popoverTriggerEl);
            });
        });
    </script>
@endsection
