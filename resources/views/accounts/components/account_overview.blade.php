<x-utils.card title="Accounts" extraClasses="mb-2">
    <div class="overflow-x-auto">
        <table class="table w-full table-zebra">
            <thead>
            <tr>
                <th class="text-left">Account Name</th>
                <th class="text-left">Money</th>
                <th class="text-left">Coal</th>
                <th class="text-left">Oil</th>
                <th class="text-left">Uranium</th>
                <th class="text-left">Iron</th>
                <th class="text-left">Bauxite</th>
                <th class="text-left">Lead</th>
                <th class="text-left">Gasoline</th>
                <th class="text-left">Munitions</th>
                <th class="text-left">Steel</th>
                <th class="text-left">Aluminum</th>
                <th class="text-left">Food</th>
            </tr>
            </thead>
            <tbody>
            @foreach($accounts as $account)
                <tr class="hover">
                    <td>
                        <a href="{{ route("accounts.view", ["accounts" => $account]) }}"
                           class="link link-primary">{{ $account->name }}</a>
                        <div class="tooltip" data-tip="Click to request a deposit">
                            <button class="deposit-request-btn relative" data-account-id="{{ $account->id }}">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                                     stroke-width="1.5" stroke="currentColor"
                                     class="size-6 text-blue-500 hover:text-blue-700 cursor-pointer">
                                    <path stroke-linecap="round" stroke-linejoin="round"
                                          d="M2.25 18.75a60.07 60.07 0 0 1 15.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 0 1 3 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 0 0-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 0 1-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 0 0 3 15h-.75M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm3 0h.008v.008H18V10.5Zm-12 0h.008v.008H6V10.5Z"/>
                                </svg>
                            </button>
                        </div>
                    </td>
                    <td>${{ number_format($account->money, 2) }}</td>
                    <td>{{ number_format($account->coal, 2) }}</td>
                    <td>{{ number_format($account->oil, 2) }}</td>
                    <td>{{ number_format($account->uranium, 2) }}</td>
                    <td>{{ number_format($account->iron, 2) }}</td>
                    <td>{{ number_format($account->bauxite, 2) }}</td>
                    <td>{{ number_format($account->lead, 2) }}</td>
                    <td>{{ number_format($account->gasoline, 2) }}</td>
                    <td>{{ number_format($account->munitions, 2) }}</td>
                    <td>{{ number_format($account->steel, 2) }}</td>
                    <td>{{ number_format($account->aluminum, 2) }}</td>
                    <td>{{ number_format($account->food, 2) }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</x-utils.card>
