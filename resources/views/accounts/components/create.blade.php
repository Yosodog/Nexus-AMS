<x-utils.card title="Create an account" extraClasses="w-full">
    <p class="text-sm text-base-content/70 mb-4">Create a separate account for projects, war prep, or repayment.</p>
    <form action="/accounts/create" method="POST" class="space-y-3">
        @csrf
        <div class="grid gap-2">
            <label class="label" for="account_name">
                <span class="font-semibold">Account name</span>
                <span class="text-base-content/60">Keep it short and clear</span>
            </label>
            <input type="text" id="account_name" name="name" class="input w-full"
                   placeholder="e.g. War Chest" required>
        </div>
        <button type="submit" class="btn btn-primary w-full">Create account</button>
    </form>
</x-utils.card>
