<x-utils.card title="Delete an Account" extraClasses="mx-auto w-34 border-error">
    <form action="{{ route("accounts.delete.post") }}" method="POST">
        @csrf
        <div class="mb-4">
            <label class="block text-gray-700 text-sm font-semibold mb-2" for="account_name_delete">Account Name</label>
            <select class="select select-bordered w-full" id="account_name_delete" name="account_id">
                @foreach ($accounts as $account)
                    <option value="{{ $account->id }}">{{ $account->name }} -
                        ${{ number_format($account->money, 2) }}</option>
                @endforeach
            </select>
        </div>
        <button type="submit" class="btn btn-error w-full">Delete Account</button>
    </form>
</x-utils.card>
