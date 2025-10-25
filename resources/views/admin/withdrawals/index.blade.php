@extends('layouts.admin')

@section('content')
    <div class="app-content-header">
        <div class="container-fluid">
            <div class="row">
                <div class="col-sm-6">
                    <h3 class="mb-0">Withdrawal Controls</h3>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-md-4">
            <x-admin.info-box icon="bi bi-hourglass-split" bgColor="text-bg-warning"
                              title="Pending Approvals" :value="$pendingWithdrawals->count()"/>
        </div>
        <div class="col-md-4">
            <x-admin.info-box icon="bi bi-arrow-down-up" bgColor="text-bg-info"
                              title="Daily Auto Withdraw Limit"
                              :value="$maxDailyWithdrawals > 0 ? $maxDailyWithdrawals : 'Unlimited'"/>
        </div>
        <div class="col-md-4">
            <x-admin.info-box icon="bi bi-shield-lock" bgColor="text-bg-success"
                              title="Resources with Limits"
                              :value="$limits->filter(fn($limit) => (float)$limit->daily_limit > 0)->count()"/>
        </div>
    </div>

    <div class="card mt-4">
        <div class="card-header">Automatic Withdrawal Limits</div>
        <div class="card-body">
            <form method="POST" action="{{ route('admin.withdrawals.limits') }}" class="row g-3">
                @csrf
                <div class="col-12">
                    <label for="max_daily_withdrawals" class="form-label fw-semibold">Maximum Automatic Withdrawals Per Day</label>
                    <input type="number" min="0" class="form-control" id="max_daily_withdrawals"
                           name="max_daily_withdrawals" value="{{ old('max_daily_withdrawals', $maxDailyWithdrawals) }}"
                           required>
                    <div class="form-text">Set to 0 to allow unlimited automatic approvals.</div>
                </div>

                <div class="col-12">
                    <div class="table-responsive">
                        <table class="table table-striped align-middle">
                            <thead>
                            <tr>
                                <th>Resource</th>
                                <th>Daily Auto-Approval Limit</th>
                            </tr>
                            </thead>
                            <tbody>
                            @foreach($resources as $resource)
                                @php($limit = optional($limits->get($resource))->daily_limit ?? 0)
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
                    <button type="submit" class="btn btn-primary">Save Limits</button>
                </div>
            </form>
        </div>
    </div>

    <div class="card mt-4">
        <div class="card-header">Pending Withdrawal Approvals</div>
        <div class="card-body">
            @if($pendingWithdrawals->isEmpty())
                <p class="mb-0">No withdrawals are awaiting approval.</p>
            @else
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
                                        @foreach($resources as $resource)
                                            @php($amount = $transaction->{$resource})
                                            @if($amount > 0)
                                                <li>
                                                    {{ ucfirst($resource) }}:
                                                    {{ $resource === 'money' ? '$' : '' }}{{ number_format($amount, 2) }}
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
                                        <button type="submit" class="btn btn-success btn-sm">Approve</button>
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
                                            <button type="submit" class="btn btn-danger btn-sm">Confirm Denial</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
@endsection
