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
                    <td><a href="{{ route("accounts.view", ["accounts" => $account]) }}" class="link link-primary">{{ $account->name }}</a></td>
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
