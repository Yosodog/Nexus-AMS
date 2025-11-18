@php use App\Services\PWHelperService; @endphp
@extends('layouts.main')

@section("content")
    @php
        $resourceList = PWHelperService::resources(false);
        $resourceTotal = collect($resourceList)->sum(fn($res) => $account->$res);
    @endphp

    <div class="mx-auto space-y-8">
        @if($account->frozen)
            <div class="alert alert-error shadow-sm">
                <div>
                    <h3 class="font-semibold">Account frozen</h3>
                    <p class="text-sm">Withdrawals and transfers are disabled for this account. Please contact an administrator for assistance.</p>
                </div>
            </div>
        @endif

        <div class="rounded-2xl border border-base-300 bg-base-100 p-6 shadow-md">
            <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                <div>
                    <p class="text-xs uppercase tracking-wide text-base-content/60">Account</p>
                    <div class="flex items-center gap-2">
                        <h1 class="text-3xl font-bold">{{ $account->name }}</h1>
                        <a href="https://politicsandwar.com/nation/id={{ $account->nation_id }}" target="_blank" class="btn btn-xs btn-ghost">
                            View nation
                        </a>
                    </div>
                    <div class="flex flex-wrap gap-2 text-sm text-base-content/70 mt-2">
                        <span class="badge badge-outline">ID {{ $account->id }}</span>
                        <span class="badge badge-outline">${{ number_format($account->money, 2) }} cash</span>
                        <span class="badge badge-outline">{{ number_format($resourceTotal, 2) }} resources</span>
                        <span class="badge {{ $account->frozen ? 'badge-error' : 'badge-success' }}">{{ $account->frozen ? 'Frozen' : 'Active' }}</span>
                    </div>
                </div>
                <div class="flex flex-wrap gap-2">
                    <div class="tooltip" data-tip="{{ $account->frozen ? 'Account is frozen' : 'Create a fresh deposit code for this account' }}">
                        <button class="btn btn-primary deposit-request-btn"
                                data-account-id="{{ $account->id }}"
                                @disabled($account->frozen)>
                            Create deposit
                        </button>
                    </div>
                    <a href="{{ route('accounts') }}" class="btn btn-ghost">Back to accounts</a>
                </div>
            </div>
        </div>

        <x-utils.card title="Balances" extraClasses="mb-2">
            <div class="overflow-x-auto rounded-xl border border-base-300">
                <table class="table w-full table-zebra">
                    <thead class="bg-base-200">
                    <tr>
                        @foreach(PWHelperService::resources() as $resource)
                            <th class="text-left">{{ ucfirst($resource) }}</th>
                        @endforeach
                    </tr>
                    </thead>
                    <tbody>
                    <tr class="hover">
                        <td class="font-semibold">${{ number_format($account->money, 2) }}</td>
                        @foreach($resourceList as $resource)
                            <td>{{ number_format($account->$resource, 2) }}</td>
                        @endforeach
                    </tr>
                    </tbody>
                </table>
            </div>
        </x-utils.card>

        <x-utils.card title="Transactions" extraClasses="mb-2">
            <div class="overflow-x-auto rounded-xl border border-base-300">
                <table class="table w-full table-zebra">
                    <thead class="bg-base-200">
                    <tr>
                        <th>Date</th>
                        <th>From</th>
                        <th>To</th>
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
                                        <a href="https://politicsandwar.com/nation/id={{ $transaction->nation_id }}"
                                           class="link link-primary" target="_blank">
                                            {{ $transaction->nation->nation_name }}
                                        </a>
                                    @else
                                        Nation #{{ $transaction->nation_id }}
                                    @endif
                                @elseif($transaction->fromAccount)
                                    <a href="{{ route('accounts.view', $transaction->fromAccount->id) }}"
                                       class="link link-primary">
                                        {{ $transaction->fromAccount->name }}
                                    </a>
                                @else
                                    N/A
                                @endif
                            </td>

                            {{-- Handle To Account --}}
                            <td>
                                @if($transaction->toAccount)
                                    <a href="{{ route('accounts.view', $transaction->toAccount->id) }}"
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

                            <td><span class="badge badge-outline">{{ ucfirst($transaction->transaction_type) }}</span></td>
                            <td class="font-semibold">${{ number_format($transaction->money, 2) }}</td>
                            @foreach($resourceList as $resource)
                                <td>{{ number_format($transaction->$resource, 2) }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <div class="mt-3">
                {{ $transactions->links() }}
            </div>
        </x-utils.card>

        <x-utils.card title="Manual Adjustments" extraClasses="mb-2">
            @if($manualTransactions->count())
                <div class="overflow-x-auto rounded-xl border border-base-300">
                    <table class="table w-full table-zebra">
                        <thead class="bg-base-200">
                        <tr>
                            <th>Date</th>
                            <th>Admin</th>
                            <th>Money</th>
                            @foreach($resourceList as $resource)
                                <th>{{ ucfirst($resource) }}</th>
                            @endforeach
                            <th>Note</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach ($manualTransactions as $transaction)
                            <tr class="hover">
                                <td>{{ $transaction->created_at->format('Y-m-d H:i') }}</td>
                                <td>
                                    @if($transaction->admin)
                                        {{ $transaction->admin->name }}
                                    @elseif($transaction->admin_id)
                                        Admin #{{ $transaction->admin_id }}
                                    @else
                                        System
                                    @endif
                                </td>
                                <td>${{ number_format($transaction->money, 2) }}</td>
                                @foreach($resourceList as $resource)
                                    <td>{{ number_format($transaction->$resource, 2) }}</td>
                                @endforeach
                                <td>{{ $transaction->note }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="mt-3">
                    {{ $manualTransactions->links() }}
                </div>
            @else
                <p class="text-center py-4 text-base-content/70">No manual adjustments found.</p>
            @endif
        </x-utils.card>

        @if($ddLogs->count())
            <x-utils.card title="Direct Deposit Activity" extraClasses="mb-2">
                <div class="overflow-x-auto rounded-xl border border-base-300">
                    <table class="table w-full table-zebra">
                        <thead class="bg-base-200">
                        <tr>
                            <th>Date</th>
                            <th>Money</th>
                            @foreach($resourceList as $resource)
                                <th>{{ ucfirst($resource) }}</th>
                            @endforeach
                        </tr>
                        </thead>
                        <tbody>
                        @foreach ($ddLogs as $log)
                            <tr class="hover">
                                <td>{{ $log->created_at->format('Y-m-d H:i') }}</td>
                                <td>${{ number_format($log->money, 2) }}</td>
                                @foreach($resourceList as $resource)
                                    <td>{{ number_format($log->$resource, 2) }}</td>
                                @endforeach
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </x-utils.card>
        @endif

        @include("accounts.components.deposit_js")
    </div>
@endsection
