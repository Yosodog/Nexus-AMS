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
                                <th>Money</th>
                                <th>Coal</th>
                                <th>Oil</th>
                                <th>Uranium</th>
                                <th>Lead</th>
                                <th>Iron</th>
                                <th>Bauxite</th>
                                <th>Gas</th>
                                <th>Munitions</th>
                                <th>Steel</th>
                                <th>Aluminum</th>
                                <th>Food</th>
                            </tr>
                            </thead>
                            <tbody>
                            <tr>
                                <td>${{ number_format($account->money, 2) }}</td>
                                <td>{{ number_format($account->coal, 2) }}</td>
                                <td>{{ number_format($account->oil, 2) }}</td>
                                <td>{{ number_format($account->uranium, 2) }}</td>
                                <td>{{ number_format($account->lead, 2) }}</td>
                                <td>{{ number_format($account->iron, 2) }}</td>
                                <td>{{ number_format($account->bauxite, 2) }}</td>
                                <td>{{ number_format($account->gas, 2) }}</td>
                                <td>{{ number_format($account->munitions, 2) }}</td>
                                <td>{{ number_format($account->steel, 2) }}</td>
                                <td>{{ number_format($account->aluminum, 2) }}</td>
                                <td>{{ number_format($account->food, 2) }}</td>
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
                            @foreach (\App\Services\AccountService::$resources as $resource)
                                <div class="col-md-3">
                                    <label for="{{ $resource }}">{{ ucfirst($resource) }}</label>
                                    <input type="number" name="{{ $resource }}" id="{{ $resource }}" class="form-control" step="0.01" placeholder="0">
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
                                <th>Money</th>
                                <th>Coal</th>
                                <th>Oil</th>
                                <th>Uranium</th>
                                <th>Iron</th>
                                <th>Bauxite</th>
                                <th>Lead</th>
                                <th>Gasoline</th>
                                <th>Munitions</th>
                                <th>Steel</th>
                                <th>Aluminum</th>
                                <th>Food</th>
                            </tr>
                            </thead>
                            <tbody>
                            @foreach ($transactions as $transaction)
                                <tr class="hover">
                                    <td>{{ $transaction->created_at->format('Y-m-d H:i') }}</td>
                                    {{-- Handle From Account --}}
                                    <td>
                                        @if($transaction->transaction_type === 'deposit' && $transaction->nation_id)
                                            @if($transaction->nation)
                                                <a href="https://politicsandwar.com/nation/id={{ $transaction->nation_id }}" class="link link-primary" target="_blank">
                                                    {{ $transaction->nation->nation_name }}
                                                </a>
                                            @else
                                                Nation #{{ $transaction->nation_id }}
                                            @endif
                                        @elseif($transaction->fromAccount)
                                            <a href="{{ route('admin.accounts.view', $transaction->fromAccount->id) }}" class="link link-primary">
                                                {{ $transaction->fromAccount->name }}
                                            </a>
                                        @else
                                            N/A
                                        @endif
                                    </td>

                                    {{-- Handle To Account --}}
                                    <td>
                                        @if($transaction->toAccount)
                                            <a href="{{ route('admin.accounts.view', $transaction->toAccount->id) }}" class="link link-primary">
                                                {{ $transaction->toAccount->name }}
                                            </a>
                                        @elseif($transaction->nation_id)
                                            @if($transaction->nation)
                                                <a href="https://politicsandwar.com/nation/id={{ $transaction->nation_id }}" class="link link-primary" target="_blank">
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
                                    <td>{{ number_format($transaction->coal, 2) }}</td>
                                    <td>{{ number_format($transaction->oil, 2) }}</td>
                                    <td>{{ number_format($transaction->uranium, 2) }}</td>
                                    <td>{{ number_format($transaction->iron, 2) }}</td>
                                    <td>{{ number_format($transaction->bauxite, 2) }}</td>
                                    <td>{{ number_format($transaction->lead, 2) }}</td>
                                    <td>{{ number_format($transaction->gasoline, 2) }}</td>
                                    <td>{{ number_format($transaction->munitions, 2) }}</td>
                                    <td>{{ number_format($transaction->steel, 2) }}</td>
                                    <td>{{ number_format($transaction->aluminum, 2) }}</td>
                                    <td>{{ number_format($transaction->food, 2) }}</td>
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
                                <th>Money</th>
                                <th>Coal</th>
                                <th>Oil</th>
                                <th>Uranium</th>
                                <th>Lead</th>
                                <th>Iron</th>
                                <th>Bauxite</th>
                                <th>Gasoline</th>
                                <th>Munitions</th>
                                <th>Steel</th>
                                <th>Aluminum</th>
                                <th>Food</th>
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
                                    <td>{{ number_format($transaction->coal, 2) }}</td>
                                    <td>{{ number_format($transaction->oil, 2) }}</td>
                                    <td>{{ number_format($transaction->uranium, 2) }}</td>
                                    <td>{{ number_format($transaction->lead, 2) }}</td>
                                    <td>{{ number_format($transaction->iron, 2) }}</td>
                                    <td>{{ number_format($transaction->bauxite, 2) }}</td>
                                    <td>{{ number_format($transaction->gasoline, 2) }}</td>
                                    <td>{{ number_format($transaction->munitions, 2) }}</td>
                                    <td>{{ number_format($transaction->steel, 2) }}</td>
                                    <td>{{ number_format($transaction->aluminum, 2) }}</td>
                                    <td>{{ number_format($transaction->food, 2) }}</td>
                                    <td>
                                        <button type="button" class="btn btn-sm btn-outline-info" data-bs-toggle="popover" data-bs-placement="top" title="Note" data-bs-content="{{ $transaction->note }}">
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

        </div>
    </div>
@endsection

@section("scripts")
    <script>
        $(document).ready( function () {
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
