@extends('layouts.main')

@section("content")
    <div class="prose w-full max-w-none mb-5">
        <h1 class="text-center flex items-center justify-center gap-2">
            {{ $account->name }}
            <a href="https://politicsandwar.com/nation/id={{ $account->nation_id }}" target="_blank">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5"
                     stroke="currentColor" class="size-6">
                    <path stroke-linecap="round" stroke-linejoin="round"
                          d="M3 3v1.5M3 21v-6m0 0 2.77-.693a9 9 0 0 1 6.208.682l.108.054a9 9 0 0 0 6.086.71l3.114-.732a48.524 48.524 0 0 1-.005-10.499l-3.11.732a9 9 0 0 1-6.085-.711l-.108-.054a9 9 0 0 0-6.208-.682L3 4.5M3 15V4.5"/>
                </svg>
            </a>
        </h1>
    </div>

    <x-utils.card title="View {{ $account->name }} Information" extraClasses="mb-2">
        <div class="overflow-x-auto">
            <table class="table w-full table-zebra">
                <thead>
                <tr>
                    @foreach(\App\Services\PWHelperService::resources() as $resource)
                        <th class="text-left">{{ ucfirst($resource) }}</th>
                    @endforeach
                </tr>
                </thead>
                <tbody>
                <tr class="hover">
                    <td>${{ number_format($account->money, 2) }}</td>
                    @foreach(\App\Services\PWHelperService::resources(false) as $resource)
                        <td>{{ number_format($account->$resource, 2) }}</td>
                    @endforeach
                </tr>
                </tbody>
            </table>
        </div>
    </x-utils.card>

    <div class="divider"></div>

    <div class="tooltip w-full" data-tip="Click to request a deposit">
        <button class="btn btn-primary flex items-center gap-2 deposit-request-btn relative w-full"
                data-account-id="{{ $account->id }}">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5"
                 stroke="currentColor" class="size-5">
                <path stroke-linecap="round" stroke-linejoin="round"
                      d="M12 4.5v15m7.5-7.5h-15"/>
            </svg>
            Create Deposit
        </button>
    </div>

    <div class="divider"></div>

    <x-utils.card title="Transactions" extraClasses="mb-2">
        <div class="overflow-x-auto">
            <table class="table w-full table-zebra">
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

                        <td>{{ ucfirst($transaction->transaction_type) }}</td>
                        <td>${{ number_format($transaction->money, 2) }}</td>
                        @foreach(\App\Services\PWHelperService::resources(false) as $resource)
                            <td>{{ number_format($transaction->$resource, 2) }}</td>
                        @endforeach
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        {{ $transactions->links() }}
    </x-utils.card>

    @include("accounts.components.deposit_js")
@endsection
