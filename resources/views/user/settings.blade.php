@extends('layouts.main')

@section('content')
    <div class="mx-auto">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="space-y-2">
                <div class="flex items-center gap-2 text-xs uppercase tracking-wide text-base-content/60">
                    <span class="badge badge-outline">Account</span>
                    <span class="badge badge-outline">Security</span>
                    <span class="badge badge-outline">Integrations</span>
                </div>
                <h1 class="text-4xl font-bold leading-tight">Your settings hub</h1>
                <p class="text-sm text-base-content/70 max-w-2xl">Update your profile, keep credentials fresh, and link external tools. Built to expand as we add new preferences.</p>
            </div>
            <div class="rounded-box bg-base-200/70 px-4 py-3 text-sm flex items-center gap-3">
                <div class="w-2 h-2 bg-success rounded-full shadow-sm"></div>
                <div>
                    <p class="font-semibold">Profile status</p>
                    <p class="text-base-content/70">All systems ready</p>
                </div>
            </div>
        </div>

        <div class="grid gap-6 lg:grid-cols-3">
            <div class="space-y-6 lg:col-span-2">
                <x-utils.card>
                    <div class="flex flex-wrap items-start justify-between gap-3 mb-4">
                        <div>
                            <h2 class="text-lg font-semibold">Account details</h2>
                            <p class="text-sm text-base-content/70">Keep your profile current. Password is optional unless you want to change it.</p>
                        </div>
                        <span class="badge badge-primary badge-outline">Profile</span>
                    </div>

                    <form method="POST" action="{{ route('user.settings.update') }}" class="space-y-6">
                        @csrf

                        <div class="grid gap-4 md:grid-cols-2">
                            <div class="form-control">
                                <label class="label" for="name">
                                    <span class="label-text font-medium">Name</span>
                                    <span class="label-text-alt text-base-content/60">Visible to your team</span>
                                </label>
                                <input type="text" id="name" name="name" value="{{ old('name', $user->name) }}"
                                       class="input input-bordered w-full" required>
                                @error('name') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                            </div>

                            <div class="form-control">
                                <label class="label" for="email">
                                    <span class="label-text font-medium">Email</span>
                                    <span class="label-text-alt text-base-content/60">Used for alerts</span>
                                </label>
                                <input type="email" id="email" name="email" value="{{ old('email', $user->email) }}"
                                       class="input input-bordered w-full" required>
                                @error('email') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                            </div>
                        </div>

                        <div class="grid gap-4 md:grid-cols-2">
                            <div class="form-control">
                                <label class="label" for="password">
                                    <span class="label-text font-medium">New password</span>
                                    <span class="label-text-alt text-base-content/60">Leave blank to keep current</span>
                                </label>
                                <input type="password" id="password" name="password" class="input input-bordered w-full">
                                @error('password') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                            </div>

                            <div class="form-control">
                                <label class="label" for="password_confirmation">
                                    <span class="label-text font-medium">Confirm new password</span>
                                </label>
                                <input type="password" id="password_confirmation" name="password_confirmation"
                                       class="input input-bordered w-full">
                            </div>
                        </div>

                        <div class="flex flex-wrap items-center justify-between gap-3 border-t border-base-200 pt-4">
                            <p class="text-sm text-base-content/70">Changes save instantly. More profile options will land here soon.</p>
                            <div class="flex items-center gap-3">
                                <button type="reset" class="btn btn-ghost">Reset</button>
                                <button type="submit" class="btn btn-primary">Save changes</button>
                            </div>
                        </div>
                    </form>
                </x-utils.card>

                <x-utils.card>
                    <div class="flex items-start justify-between gap-3 mb-3">
                        <div>
                            <h2 class="text-lg font-semibold">Security & access</h2>
                            <p class="text-sm text-base-content/70">Stay confident your account is protected.</p>
                        </div>
                        <span class="badge badge-outline">Coming soon</span>
                    </div>

                    <div class="grid gap-4 md:grid-cols-2">
                        <div class="rounded-box bg-base-200/70 p-4 space-y-3">
                            <div class="flex items-center justify-between">
                                <p class="text-sm font-medium">Login activity</p>
                                <span class="badge badge-sm">Soon</span>
                            </div>
                            <p class="text-xs text-base-content/70">We’ll list your recent devices and sign-ins here for quick reviews.</p>
                            <button class="btn btn-sm btn-outline" disabled>View sessions</button>
                        </div>
                        <div class="rounded-box bg-base-200/70 p-4 space-y-3">
                            <div class="flex items-center justify-between">
                                <p class="text-sm font-medium">Recovery options</p>
                                <span class="badge badge-sm">Soon</span>
                            </div>
                            <p class="text-xs text-base-content/70">Backup codes and safety contacts will appear here once available.</p>
                            <button class="btn btn-sm btn-outline" disabled>Manage recovery</button>
                        </div>
                    </div>
                </x-utils.card>
            </div>

            <div class="space-y-6">
                <x-utils.card>
                    <div class="flex items-start justify-between gap-3 mb-4">
                        <div>
                            <h2 class="text-lg font-semibold">Discord</h2>
                            <p class="text-sm text-base-content/70">Link to verify your identity and unlock coordination tools.</p>
                        </div>
                        <span class="badge badge-outline">{{ $discordAccount ? 'Connected' : 'Not linked' }}</span>
                    </div>

                    @if($discordAccount)
                        <div class="rounded-box bg-base-200/70 p-4">
                            <div class="flex items-center justify-between gap-4">
                                <div>
                                    <p class="font-semibold text-lg">{{ $discordAccount->discord_username }}</p>
                                    <p class="text-sm text-base-content/70">ID: {{ $discordAccount->discord_id }}</p>
                                    <p class="text-xs text-base-content/70">Linked {{ optional($discordAccount->linked_at)->diffForHumans() ?? 'recently' }}</p>
                                </div>
                                <form method="POST" action="{{ route('discord.unlink') }}">
                                    @csrf
                                    <button class="btn btn-outline btn-error">Unlink</button>
                                </form>
                            </div>
                        </div>
                    @else
                        <div class="space-y-4">
                            <div class="alert alert-info">
                                <div>
                                    <h3 class="font-semibold text-base">Link your Discord</h3>
                                    <p class="text-sm">{{ $discordVerificationRequired ? 'Discord verification is required after your in-game verification.' : 'Discord linking is optional but recommended for smoother coordination.' }}</p>
                                </div>
                            </div>

                            <div class="rounded-box bg-base-200 p-4 flex items-center justify-between gap-3">
                                <div>
                                    <p class="text-xs text-base-content/70">Verification token</p>
                                    <p class="font-mono text-lg">{{ $discordVerificationToken }}</p>
                                </div>
                                <button type="button" class="btn btn-outline btn-primary" data-copy-token="{{ $discordVerificationToken }}">
                                    Copy
                                </button>
                            </div>

                            <p class="text-sm text-base-content/70">
                                Send <span class="font-mono bg-base-200 px-2 py-1 rounded">/verify {{ $discordVerificationToken }}</span> in Discord or use the verification page to complete linking.
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

                <x-utils.card>
                    <div class="flex items-start justify-between gap-3">
                        <div class="space-y-1">
                            <h3 class="text-lg font-semibold">Shortcuts</h3>
                            <p class="text-sm text-base-content/70">Quick actions and new settings will appear here as we roll them out.</p>
                        </div>
                        <div class="badge badge-outline">Helper</div>
                    </div>
                    <div class="mt-4 grid gap-3">
                        <a href="{{ route('user.dashboard') }}" class="btn btn-outline btn-sm justify-start">Back to dashboard</a>
                        <a href="{{ route('discord.verify.show') }}" class="btn btn-outline btn-sm justify-start">Open Discord verification</a>
                        <button class="btn btn-ghost btn-sm justify-start" disabled>More settings coming soon</button>
                    </div>
                </x-utils.card>
            </div>
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
