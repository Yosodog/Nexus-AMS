@php use App\Services\PWHelperService; @endphp
@php
    $resourceList = PWHelperService::resources();
    $totals = collect($resourceList)->mapWithKeys(fn($res) => [$res => $accounts->sum($res)]);
@endphp
<x-utils.card title="Your accounts" extraClasses="mb-2">
    <div class="flex items-center justify-between mb-3 text-sm text-base-content/70">
        <p>Snapshot of balances across every account with quick deposit requests.</p>
        <span class="badge badge-outline">{{ $accounts->count() }} active</span>
    </div>
    <div class="overflow-x-auto rounded-xl border border-base-300">
        <table class="table w-full table-zebra">
            <thead class="bg-base-200 text-sm">
            <tr>
                <th class="text-left">Account</th>
                @foreach ($resourceList as $resource)
                    <th class="text-left">{{ ucfirst($resource) }}</th>
                @endforeach
            </tr>
            </thead>
            <tbody>
            @foreach($accounts as $account)
                <tr class="hover">
                    <td class="font-semibold flex items-center gap-2">
                        <a href="{{ route('accounts.view', ['accounts' => $account]) }}"
                           class="link link-primary">
                            {{ $account->name }}
                        </a>

                        <div class="tooltip" data-tip="Generate deposit code">
                            <button class="btn btn-square btn-ghost btn-xs deposit-request-btn"
                                    data-account-id="{{ $account->id }}">
                                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 20 20" fill="currentColor">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-11a1 1 0 10-2 0v2H7a1 1 0 100 2h2v2a1 1 0 102 0v-2h2a1 1 0 100-2h-2V7z" clip-rule="evenodd"/>
                                </svg>
                            </button>
                        </div>
                    </td>
                    <td>${{ number_format($account->money, 2) }}</td>
                    @foreach (PWHelperService::resources(false) as $resource)
                        <td>{{ number_format($account->$resource, 2) }}</td>
                    @endforeach
                </tr>
            @endforeach
            </tbody>
            <tfoot class="bg-base-200">
            <tr>
                <th>Totals</th>
                @foreach ($resourceList as $resource)
                    <th>{{ $resource === 'money' ? '$' : '' }}{{ number_format($totals[$resource] ?? 0, 2) }}</th>
                @endforeach
            </tr>
            </tfoot>
        </table>
    </div>
</x-utils.card>
