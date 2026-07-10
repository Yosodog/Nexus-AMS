<x-utils.card title="Close an account" extraClasses="w-full border-error/40">
    <p class="text-sm text-base-content/70 mb-3">Move funds out before removing an account to avoid surprises.</p>
    <form action="{{ route("accounts.delete.post") }}" method="POST" class="space-y-3" data-confirm="Delete the selected account? Confirm its balances are empty; deleted accounts cannot be restored here." data-confirm-title="Delete account?" data-confirm-label="Delete account" data-confirm-tone="error">
        @csrf
        <div class="grid gap-2">
            <label class="label" for="account_name_delete">
                <span class="font-semibold">Account</span>
                <span class="text-base-content/60">Balance shown for safety</span>
            </label>
            <select class="select w-full" id="account_name_delete" name="account_id">
                @foreach ($accounts as $account)
                    <option value="{{ $account->id }}">{{ $account->name }} -
                        ${{ number_format($account->money, 2) }}</option>
                @endforeach
            </select>
        </div>
        <button type="submit" class="btn btn-error w-full">Delete account</button>
    </form>
</x-utils.card>
