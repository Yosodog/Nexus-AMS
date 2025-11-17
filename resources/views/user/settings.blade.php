@extends('layouts.main')

@section('content')
    <div class="max-w-lg mx-auto mt-10">
        <x-utils.card title="User Settings">
            <form method="POST" action="{{ route('user.settings.update') }}">
                @csrf

                <div class="mb-4">
                    <label class="label" for="name">Name</label>
                    <input type="text" id="name" name="name" value="{{ old('name', $user->name) }}"
                           class="input input-bordered w-full" required>
                    @error('name') <p class="text-red-500 text-sm">{{ $message }}</p> @enderror
                </div>

                <div class="mb-4">
                    <label class="label" for="email">Email</label>
                    <input type="email" id="email" name="email" value="{{ old('email', $user->email) }}"
                           class="input input-bordered w-full" required>
                    @error('email') <p class="text-red-500 text-sm">{{ $message }}</p> @enderror
                </div>

                <div class="mb-4">
                    <label class="label" for="password">New Password (leave blank to keep current)</label>
                    <input type="password" id="password" name="password" class="input input-bordered w-full">
                    @error('password') <p class="text-red-500 text-sm">{{ $message }}</p> @enderror
                </div>

                <div class="mb-4">
                    <label class="label" for="password_confirmation">Confirm New Password</label>
                    <input type="password" id="password_confirmation" name="password_confirmation"
                           class="input input-bordered w-full">
                </div>

                <button type="submit" class="btn btn-primary w-full">Update Settings</button>
            </form>
        </x-utils.card>

        <div class="mt-6">
            <x-utils.card title="Discord">
                @if($discordAccount)
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="font-semibold text-lg">{{ $discordAccount->discord_username }}</p>
                            <p class="text-sm text-base-content/70">ID: {{ $discordAccount->discord_id }}</p>
                            <p class="text-xs text-base-content/70">Linked {{ optional($discordAccount->linked_at)->diffForHumans() ?? 'recently' }}</p>
                        </div>
                        <form method="POST" action="{{ route('discord.unlink') }}">
                            @csrf
                            <button class="btn btn-outline btn-error">Unlink Discord</button>
                        </form>
                    </div>
                @else
                    <div class="space-y-4">
                        <p class="text-sm">
                            {{ $discordVerificationRequired ? 'Discord verification is required after your in-game verification.' : 'Discord linking is optional but recommended for smoother coordination.' }}
                        </p>

                        <div class="rounded-box bg-base-200 p-4 flex items-center justify-between gap-3">
                            <div>
                                <p class="text-xs text-base-content/70">Verification Token</p>
                                <p class="font-mono text-lg">{{ $discordVerificationToken }}</p>
                            </div>
                            <button type="button" class="btn btn-outline btn-primary" data-copy-token="{{ $discordVerificationToken }}">
                                Copy
                            </button>
                        </div>

                        <p class="text-sm text-base-content/70">
                            Send <span class="font-mono bg-base-200 px-2 py-1 rounded">/verify {{ $discordVerificationToken }}</span> in Discord to link your account.
                        </p>

                        <div class="flex flex-wrap gap-3">
                            <form method="POST" action="{{ route('discord.token.regenerate') }}">
                                @csrf
                                <button class="btn btn-outline">Regenerate Token</button>
                            </form>
                            <a href="{{ route('discord.verify.show') }}" class="btn btn-link">Open verification page</a>
                        </div>
                    </div>
                @endif
            </x-utils.card>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            document.querySelectorAll('[data-copy-token]').forEach((button) => {
                button.addEventListener('click', async () => {
                    const token = button.getAttribute('data-copy-token');

                    try {
                        await navigator.clipboard.writeText(token);
                        const originalText = button.innerText;
                        button.innerText = 'Copied!';
                        setTimeout(() => button.innerText = originalText, 1500);
                    } catch (error) {
                        console.error('Could not copy token', error);
                    }
                });
            });
        });
    </script>
@endpush
