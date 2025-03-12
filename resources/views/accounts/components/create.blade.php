<x-utils.card title="Create an Account" extraClasses="mx-auto w-34">
    <form action="/accounts/create" method="POST">
        @csrf
        <div class="mb-4">
            <label class="block text-gray-700 text-sm font-semibold mb-2" for="account_name">Account Name</label>
            <input type="text" id="account_name" name="name" class="input input-bordered w-full"
                   placeholder="Enter account name" required>
        </div>
        <button type="submit" class="btn btn-primary w-full">Create Account</button>
    </form>
</x-utils.card>
