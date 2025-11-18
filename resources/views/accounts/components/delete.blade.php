<x-utils.card title="Close an account" extraClasses="w-full border-error/40">
    <p class="text-sm text-base-content/70 mb-3">Move funds out before removing an account to avoid surprises.</p>
    <form action="{{ route("accounts.delete.post") }}" method="POST" class="space-y-3">
        @csrf
        <div class="form-control">
            <label class="label" for="account_name_delete">
                <span class="label-text font-semibold">Account</span>
                <span class="label-text-alt text-base-content/60">Balance shown for safety</span>
            </label>
            <select class="select select-bordered w-full" id="account_name_delete" name="account_id">
                @foreach ($accounts as $account)
                    <option value="{{ $account->id }}">{{ $account->name }} -
                        ${{ number_format($account->money, 2) }}</option>
                @endforeach
            </select>
        </div>
        <button type="submit" class="btn btn-error w-full">Delete account</button>
    </form>
</x-utils.card>
