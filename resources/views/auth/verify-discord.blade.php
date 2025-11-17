@extends('layouts.main')

@section('content')
    <div class="max-w-3xl mx-auto mt-10">
        <x-utils.card title="Discord Verification">
            @if($discordAccount)
                <div class="alert alert-success shadow-sm">
                    <div>
                        <h3 class="font-semibold">Discord linked</h3>
                        <p class="text-sm">Connected as {{ $discordAccount->discord_username }} (ID: {{ $discordAccount->discord_id }}).</p>
                        <p class="text-xs text-base-content/70">Linked {{ optional($discordAccount->linked_at)->diffForHumans() ?? 'just now' }}.</p>
                    </div>
                </div>

                <form method="POST" action="{{ route('discord.unlink') }}" class="mt-4">
                    @csrf
                    <button class="btn btn-outline btn-error">Unlink Discord</button>
                </form>
            @else
                <div class="alert alert-info shadow-sm">
                    <div>
                        <h3 class="font-semibold">Finish Discord verification</h3>
                        <p class="text-sm">
                            Send <span class="font-mono bg-base-200 px-2 py-1 rounded">/verify {{ $verificationToken }}</span> in Discord to complete the link.
                            {{ $discordRequired ? 'Discord verification is required before continuing.' : 'Linking is optional but recommended.' }}
                        </p>
                    </div>
                </div>

                <div class="space-y-4">
                    <div class="bg-base-200 rounded-box p-4 flex items-center justify-between gap-3">
                        <div>
                            <p class="text-xs text-base-content/70">Your verification token</p>
                            <p class="font-mono text-lg">{{ $verificationToken }}</p>
                        </div>
                        <button type="button" class="btn btn-outline btn-primary" data-copy-token="{{ $verificationToken }}">
                            Copy
                        </button>
                    </div>

                    <p class="text-sm text-base-content/70">
                        Paste the command exactly as shown in the Discord server. The bot will confirm and your access here will be unlocked automatically.
                    </p>

                    <div class="flex flex-wrap gap-3">
                        <form method="POST" action="{{ route('discord.token.regenerate') }}">
                            @csrf
                            <button class="btn btn-outline">Regenerate Token</button>
                        </form>
                        <a href="{{ route('user.settings') }}" class="btn btn-link">Back to Settings</a>
                    </div>
                </div>
            @endif
        </x-utils.card>
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
