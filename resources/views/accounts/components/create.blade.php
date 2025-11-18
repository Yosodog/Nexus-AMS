<x-utils.card title="Create an account" extraClasses="w-full">
    <p class="text-sm text-base-content/70 mb-4">Spin up a dedicated bucket for projects, war prep, or repayment.</p>
    <form action="/accounts/create" method="POST" class="space-y-3">
        @csrf
        <div class="form-control">
            <label class="label" for="account_name">
                <span class="label-text font-semibold">Account name</span>
                <span class="label-text-alt text-base-content/60">Keep it short and clear</span>
            </label>
            <input type="text" id="account_name" name="name" class="input input-bordered w-full"
                   placeholder="e.g. War Chest" required>
        </div>
        <button type="submit" class="btn btn-primary w-full">Create account</button>
    </form>
</x-utils.card>
