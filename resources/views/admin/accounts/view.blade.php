@php use App\Services\AccountService;use App\Services\PWHelperService; @endphp
@extends('layouts.admin')

@section('content')
    <x-header :title="'Account: ' . $account->name" separator>
        <x-slot:actions>
            <x-badge : value="$account->frozen ? 'Frozen' : 'Active'"
                     :class="$account->frozen ? 'badge-error' : 'badge-success'" />
            @can('manage-accounts')
                <form method="POST"
                      action="{{ $account->frozen ? route('admin.accounts.unfreeze', $account) : route('admin.accounts.freeze', $account) }}"
                      onsubmit="return confirm('Are you sure you want to {{ $account->frozen ? 'unfreeze' : 'freeze' }} this account?');">
                    @csrf
                    <x-button :label="$account->frozen ? 'Unfreeze' : 'Freeze'"
                              :icon="$account->frozen ? 'o-lock-open' : 'o-lock-closed'"
                              type="submit"
                              :class="$account->frozen ? 'btn-success btn-outline btn-sm' : 'btn-error btn-outline btn-sm'" />
                </form>
            @endcan
        </x-slot:actions>
    </x-header>

    {{-- Balance --}}
    <x-card title="Balance" class="mb-4">
        <div class="overflow-x-auto">
            <table class="table table-sm table-zebra">
                <thead>
                    <tr class="text-base-content/60">
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
    </x-card>

    {{-- Manual Adjustment --}}
    <x-card title="Manual Adjustment" class="mb-4">
        <form action="{{ route('admin.accounts.adjust', $account->id) }}" method="POST">
            @csrf
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4">
                @foreach (PWHelperService::resources() as $resource)
                    <x-input :label="ucfirst($resource)" type="number" name="{{ $resource }}"
                             step="0.01" placeholder="0" />
                @endforeach
            </div>
            <x-input label="Note (Reason for Adjustment)" name="note" required class="mb-4" />
            <input type="hidden" name="accountId" value="{{ $account->id }}">
            <x-button label="Adjust Balance" type="submit" icon="o-adjustments-horizontal" class="btn-primary" />
        </form>
    </x-card>

    {{-- Last 500 Transactions --}}
    <x-card class="mb-4" x-data="{ search: '' }">
        <x-slot:title>Last 500 Transactions</x-slot:title>
        <x-slot:menu>
            <x-input placeholder="Search..." x-model="search" icon="o-magnifying-glass" class="input-sm w-48" clearable />
        </x-slot:menu>
        <div class="overflow-x-auto">
            <table class="table table-sm table-zebra text-nowrap">
                <thead>
                    <tr class="text-base-content/60">
                        <th>Date</th>
                        <th>From Account</th>
                        <th>To Account</th>
                        <th>Type</th>
                        <th class="text-right">Money</th>
                        @foreach(PWHelperService::resources(false) as $resource)
                            <th class="text-right">{{ ucfirst($resource) }}</th>
                        @endforeach
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($transactions as $transaction)
                        <tr x-show="!search || {{ \Illuminate\Support\Js::from(strtolower($transaction->transaction_type)) }}.includes(search.toLowerCase())">
                            <td>{{ $transaction->created_at->format('Y-m-d H:i') }}</td>
                            <td>
                                @if($transaction->transaction_type === 'deposit' && $transaction->nation_id)
                                    @if($transaction->nation)
                                        <a href="https://politicsandwar.com/nation/id={{ $transaction->nation_id }}" class="link link-primary" target="_blank">
                                            {{ $transaction->nation->nation_name }}
                                        </a>
                                    @else
                                        Nation #{{ $transaction->nation_id }}
                                    @endif
                                @elseif($transaction->transaction_type === 'payroll')
                                    <span class="text-base-content/50">Payroll</span>
                                    @if($transaction->payrollGrade)
                                        <div class="text-xs text-base-content/40">{{ $transaction->payrollGrade->name }}</div>
                                    @endif
                                @elseif($transaction->fromAccount)
                                    <a href="{{ route('admin.accounts.view', $transaction->fromAccount->id) }}" class="link link-primary">
                                        {{ $transaction->fromAccount->name }}
                                    </a>
                                @else
                                    <span class="text-base-content/50">N/A</span>
                                @endif
                            </td>
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
                                    <span class="text-base-content/50">N/A</span>
                                @endif
                            </td>
                            <td>{{ ucfirst($transaction->transaction_type) }}</td>
                            <td class="text-right">${{ number_format($transaction->money, 2) }}</td>
                            @foreach(PWHelperService::resources(false) as $resource)
                                <td class="text-right">{{ number_format($transaction->$resource, 2) }}</td>
                            @endforeach
                            <td>
                                @if($transaction->isNationWithdrawal() && !$transaction->isRefunded() && Gate::allows('manage-accounts'))
                                    <form method="POST"
                                          action="{{ route('admin.accounts.transactions.refund', $transaction) }}"
                                          onsubmit="return confirm('Are you sure you want to refund this transaction?');">
                                        @csrf
                                        <x-button label="Refund" icon="o-arrow-uturn-left" type="submit" class="btn-error btn-outline btn-xs" />
                                    </form>
                                @elseif($transaction->isRefunded())
                                    <span class="tooltip" data-tip="This transaction was refunded.">
                                        <x-badge  value="Refunded" class="badge-ghost badge-sm" />
                                    </span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-card>

    {{-- Stuck Pending Withdrawals --}}
    @if(isset($stuckTransactions) && $stuckTransactions->isNotEmpty())
        <x-card title="Stuck Pending Withdrawals" class="mb-4 border-warning">
            <x-slot:subtitle>Transactions that are still pending and require manual intervention.</x-slot:subtitle>
            <x-slot:menu>
                <x-badge  value="{{ $stuckTransactions->count() }} stuck" class="badge-warning" />
            </x-slot:menu>
            <div class="overflow-x-auto">
                <table class="table table-sm table-zebra">
                    <thead>
                        <tr class="text-base-content/60">
                            <th>Date</th>
                            <th>Amount</th>
                            <th>Nation</th>
                            <th>Pending Reason</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($stuckTransactions as $transaction)
                            <tr>
                                <td>{{ $transaction->created_at->format('Y-m-d H:i') }}</td>
                                <td>${{ number_format($transaction->money, 2) }}</td>
                                <td>
                                    @if($transaction->nation)
                                        <a href="https://politicsandwar.com/nation/id={{ $transaction->nation_id }}" target="_blank" class="link link-primary">
                                            {{ $transaction->nation->nation_name }}
                                        </a>
                                    @elseif($transaction->nation_id)
                                        Nation #{{ $transaction->nation_id }}
                                    @else
                                        <span class="text-base-content/50">N/A</span>
                                    @endif
                                </td>
                                <td>{{ $transaction->pending_reason ?? 'Unspecified' }}</td>
                                <td>
                                    @can('manage-accounts')
                                        <form method="POST"
                                              action="{{ route('admin.accounts.transactions.unstuck_refund', $transaction) }}"
                                              onsubmit="return confirm('Unstick and refund this pending withdrawal?');">
                                            @csrf
                                            <x-button label="Unstick + Refund" icon="o-arrow-uturn-left" type="submit" class="btn-error btn-outline btn-sm" />
                                        </form>
                                    @endcan
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-card>
    @endif

    {{-- Last 500 Manual Adjustments --}}
    <x-card class="mb-4">
        <x-slot:title>Last 500 Manual Adjustments</x-slot:title>
        <div class="overflow-x-auto">
            <table class="table table-sm table-zebra text-nowrap">
                <thead>
                    <tr class="text-base-content/60">
                        <th>Date</th>
                        <th>Admin</th>
                        <th class="text-right">Money</th>
                        @foreach(PWHelperService::resources(false) as $resource)
                            <th class="text-right">{{ ucfirst($resource) }}</th>
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
                                    <span class="text-error">Admin #{{ $transaction->admin_id }} (Deleted)</span>
                                @endif
                            </td>
                            <td class="text-right">${{ number_format($transaction->money, 2) }}</td>
                            @foreach(PWHelperService::resources(false) as $resource)
                                <td class="text-right">{{ number_format($transaction->$resource, 2) }}</td>
                            @endforeach
                            <td>
                                <div class="dropdown dropdown-end">
                                    <label tabindex="0" class="btn btn-xs btn-ghost">View Note</label>
                                    <div tabindex="0" class="dropdown-content z-10 p-3 shadow bg-base-100 border border-base-300 rounded-box w-52 text-sm">
                                        {{ $transaction->note }}
                                    </div>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-card>

    {{-- Direct Deposit Logs --}}
    <x-card id="direct-deposit-logs" class="mb-4">
        <x-slot:title>
            <div class="flex items-center gap-2">
                <x-badge  value="DD" class="badge-primary" /> Direct Deposit Logs
            </div>
            <div class="text-sm font-normal text-base-content/50">After-tax payouts tagged to this account.</div>
        </x-slot:title>
        <x-slot:menu>
            <a href="#mmr-assistant" class="link link-primary text-sm">Jump to MMR Assistant</a>
        </x-slot:menu>
        <div class="overflow-x-auto">
            <table class="table table-sm table-zebra text-nowrap">
                <thead>
                    <tr class="text-base-content/60">
                        <th>Date</th>
                        <th>Nation</th>
                        <th class="text-right">Cash Paid</th>
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
                                <a href="https://politicsandwar.com/nation/id={{ $log->nation_id }}" target="_blank" class="link link-primary">
                                    Nation #{{ $log->nation_id }}
                                </a>
                            </td>
                            <td class="text-right">${{ number_format((float) $log->money, 2) }}</td>
                            <td>
                                @if($deliveredResources->isNotEmpty())
                                    <div class="flex flex-wrap gap-1">
                                        @foreach($deliveredResources as $resource => $amount)
                                            <x-badge  value="{{ ucfirst($resource) }}: {{ number_format((float) $amount, 2) }}" class="badge-ghost badge-sm" />
                                        @endforeach
                                    </div>
                                @else
                                    <span class="text-base-content/50">Money only</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="text-center text-base-content/50 py-4">No direct deposit activity for this account.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <x-slot:footer>
            <div class="flex items-center gap-2">
                <span class="text-sm text-base-content/50">Showing {{ $directDepositLogs->count() }} of {{ $directDepositLogs->total() }} entries</span>
                <div class="ml-auto">{{ $directDepositLogs->withQueryString()->links() }}</div>
            </div>
        </x-slot:footer>
    </x-card>

    {{-- MMR Assistant Purchases --}}
    <x-card id="mmr-assistant" class="mb-4">
        <x-slot:title>
            <div class="flex items-center gap-2">
                <x-badge  value="MMR" class="badge-neutral" /> MMR Assistant Purchases
            </div>
            <div class="text-sm font-normal text-base-content/50">Withheld cash converted into resources via MMR Assistant.</div>
        </x-slot:title>
        <x-slot:menu>
            <a href="#direct-deposit-logs" class="link link-primary text-sm">Back to DD Logs</a>
        </x-slot:menu>
        <div class="overflow-x-auto">
            <table class="table table-sm table-zebra text-nowrap">
                <thead>
                    <tr class="text-base-content/60">
                        <th>Date</th>
                        <th class="text-right">Total Spent</th>
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
                            <td class="text-right">${{ number_format((float) $purchase->total_spent, 2) }}</td>
                            <td>
                                @if($purchasedResources->isNotEmpty())
                                    <div class="flex flex-wrap gap-1">
                                        @foreach($purchasedResources as $resource => $data)
                                            <x-badge class="badge-ghost badge-sm">
                                                {{ ucfirst($resource) }}: {{ number_format((float) $data['qty'], 2) }}
                                                @if($data['ppu'])
                                                    <span class="text-base-content/50"> @ ${{ number_format((float) $data['ppu'], 2) }}</span>
                                                @endif
                                            </x-badge>
                                        @endforeach
                                    </div>
                                @else
                                    <span class="text-base-content/50">No resources purchased</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="3" class="text-center text-base-content/50 py-4">No MMR Assistant purchases for this account.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <x-slot:footer>
            <div class="flex items-center gap-2">
                <span class="text-sm text-base-content/50">Showing {{ $mmrPurchases->count() }} of {{ $mmrPurchases->total() }} purchases</span>
                <div class="ml-auto">{{ $mmrPurchases->withQueryString()->links() }}</div>
            </div>
        </x-slot:footer>
    </x-card>
@endsection
