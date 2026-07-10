@extends('layouts.public')

@php
    $discordCommand = $verificationToken ? '/verify '.$verificationToken : null;
    $pageBadge = $discordAccount
        ? 'Discord connection · Complete'
        : ($discordRequired ? 'Access check · Required' : 'Account connection · Optional');
    $pageTitle = $discordAccount ? 'Discord connected' : 'Connect Discord';
    $pageDescription = $discordAccount
        ? 'Your member account is linked to a Discord identity.'
        : ($discordRequired
            ? 'Your alliance requires a verified Discord account before member tools become available.'
            : 'Link Discord so your member account can be matched to your identity in alliance coordination spaces.');
@endphp

@section('title', $pageTitle.' · '.config('app.name'))

@section('content')
    <x-auth.shell :badge="$pageBadge" :title="$pageTitle" :description="$pageDescription">
        <x-slot:context>
            @if($discordAccount)
                <div class="inline-grid size-10 place-items-center rounded-lg bg-success text-success-content" aria-hidden="true">
                    <x-icon name="o-check" class="size-5" />
                </div>
                <h2 class="mt-5 font-display text-2xl font-bold tracking-[-0.02em]">Connection complete</h2>
                <p class="mt-3 text-sm leading-6 text-neutral-content/70">
                    Discord has confirmed the account shown here. You can continue to the member app or manage this connection below.
                </p>
                @if($discordRequired)
                    <p class="mt-6 border-t border-neutral-content/15 pt-5 text-xs leading-5 text-neutral-content/70">
                        This required access check is complete. Other configured security checks may still apply.
                    </p>
                @endif
            @else
                <h2 class="font-display text-2xl font-bold tracking-[-0.02em]">Connect in Discord</h2>
                <p class="mt-3 text-sm leading-6 text-neutral-content/70">
                    The verification command is unique to your signed-in account. Do not share it with anyone else.
                </p>

                <ol class="mt-7 space-y-5">
                    <x-auth.journey-step
                        number="1"
                        state="current"
                        title="Copy the command"
                        description="Use the complete command shown on this page."
                    />
                    <x-auth.journey-step
                        number="2"
                        title="Send it in Discord"
                        description="Run the command where your alliance bot accepts verification."
                    />
                    <x-auth.journey-step
                        number="3"
                        title="Check the connection"
                        description="Return here after the bot confirms your account."
                    />
                </ol>
            @endif
        </x-slot:context>

        <div class="space-y-6">
            <x-auth.error-summary title="We could not update your Discord connection." />

            @if($discordAccount)
                <div class="alert alert-success items-start" role="status">
                    <x-icon name="o-check-circle" class="mt-0.5 size-5 shrink-0" aria-hidden="true" />
                    <div>
                        <p class="font-semibold">Discord identity verified</p>
                        <p class="mt-1 text-sm">Connected as {{ $discordAccount->discord_username }}.</p>
                    </div>
                </div>

                <dl class="divide-y divide-base-300 border-y border-base-300 text-sm">
                    <div class="flex flex-col gap-1 py-4 sm:flex-row sm:items-baseline sm:justify-between sm:gap-6">
                        <dt class="font-medium text-base-content/70">Discord username</dt>
                        <dd class="break-all font-semibold text-base-content">{{ $discordAccount->discord_username }}</dd>
                    </div>
                    <div class="flex flex-col gap-1 py-4 sm:flex-row sm:items-baseline sm:justify-between sm:gap-6">
                        <dt class="font-medium text-base-content/70">Discord ID</dt>
                        <dd class="break-all font-mono text-base-content">{{ $discordAccount->discord_id }}</dd>
                    </div>
                    <div class="flex flex-col gap-1 py-4 sm:flex-row sm:items-baseline sm:justify-between sm:gap-6">
                        <dt class="font-medium text-base-content/70">Connected</dt>
                        <dd class="text-base-content">{{ optional($discordAccount->linked_at)->diffForHumans() ?? 'Just now' }}</dd>
                    </div>
                </dl>

                <div class="flex flex-col gap-3 sm:flex-row sm:flex-wrap">
                    <a href="{{ route('user.dashboard') }}" class="btn btn-primary">Continue to member app</a>
                    <a href="{{ route('user.settings') }}" class="btn btn-ghost">Open account settings</a>
                </div>

                <section class="border-t border-base-300 pt-6" aria-labelledby="disconnect-discord-title">
                    <h2 id="disconnect-discord-title" class="font-display text-xl font-bold text-base-content">Disconnect Discord</h2>
                    <p class="mt-2 text-sm leading-6 text-base-content/70">
                        @if($discordRequired)
                            Disconnecting will block member tools until you verify another Discord account.
                        @else
                            This removes the link from your member account. You can reconnect later.
                        @endif
                    </p>
                    <form method="POST" action="{{ route('discord.unlink') }}" class="mt-4">
                        @csrf
                        <button type="submit" class="btn btn-outline btn-error">Disconnect Discord account</button>
                    </form>
                </section>
            @else
                <div @class([
                    'alert items-start',
                    'alert-warning' => $discordRequired,
                    'alert-info' => ! $discordRequired,
                ]) role="status">
                    <x-icon
                        :name="$discordRequired ? 'o-exclamation-triangle' : 'o-information-circle'"
                        class="mt-0.5 size-5 shrink-0"
                        aria-hidden="true"
                    />
                    <div>
                        <p class="font-semibold">{{ $discordRequired ? 'Discord verification is required' : 'Discord linking is optional' }}</p>
                        <p class="mt-1 text-sm leading-6">
                            {{ $discordRequired
                                ? 'Complete the connection below to continue into the member app.'
                                : 'You can connect now or return to account settings without making a change.' }}
                        </p>
                    </div>
                </div>

                <section aria-labelledby="discord-command-title">
                    <h2 id="discord-command-title" class="font-display text-xl font-bold text-base-content">Your Discord command</h2>
                    <p class="mt-2 text-sm leading-6 text-base-content/70">
                        Send this exact command in Discord. The command links the Discord account that sends it.
                    </p>

                    <div class="mt-4 rounded-lg bg-neutral p-4 text-neutral-content">
                        <p class="text-xs font-medium text-neutral-content/75">Verification command</p>
                        <div class="mt-2 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <code class="min-w-0 break-all font-mono text-base font-semibold">{{ $discordCommand }}</code>
                            <button
                                type="button"
                                class="btn btn-outline shrink-0 border-neutral-content/35 text-neutral-content hover:border-neutral-content hover:bg-neutral-content hover:text-neutral"
                                data-copy-token="{{ $discordCommand }}"
                                aria-describedby="copy-token-status"
                            >
                                <span data-copy-label>Copy Discord command</span>
                            </button>
                        </div>
                        <p id="copy-token-status" class="mt-2 min-h-5 text-xs text-neutral-content/70" aria-live="polite" aria-atomic="true"></p>
                    </div>
                </section>

                <div class="flex flex-col gap-3 sm:flex-row sm:flex-wrap">
                    <a href="{{ route('discord.verify.show') }}" class="btn btn-primary">Check Discord connection</a>
                    @unless($discordRequired)
                        <a href="{{ route('user.settings') }}" class="btn btn-ghost">Return to account settings</a>
                    @endunless
                </div>

                <section class="border-t border-base-300 pt-6" aria-labelledby="new-discord-command-title">
                    <h2 id="new-discord-command-title" class="font-display text-lg font-bold text-base-content">Command not working?</h2>
                    <p class="mt-2 text-sm leading-6 text-base-content/70">
                        Generate a new command if this one was exposed or rejected. The current command will stop working.
                    </p>
                    <form method="POST" action="{{ route('discord.token.regenerate') }}" class="mt-4">
                        @csrf
                        <button type="submit" class="btn btn-outline">Generate a new Discord command</button>
                    </form>
                </section>
            @endif
        </div>
    </x-auth.shell>
@endsection

@unless($discordAccount)
    @push('scripts')
        <script>
            document.addEventListener('DOMContentLoaded', () => {
                const button = document.querySelector('[data-copy-token]');

                if (!button) {
                    return;
                }

                const label = button.querySelector('[data-copy-label]');
                const status = document.getElementById('copy-token-status');
                const originalLabel = label.textContent;

                button.addEventListener('click', async () => {
                    const command = button.getAttribute('data-copy-token');

                    try {
                        if (navigator.clipboard && window.isSecureContext) {
                            await navigator.clipboard.writeText(command);
                        } else {
                            const textArea = document.createElement('textarea');
                            textArea.value = command;
                            textArea.setAttribute('readonly', '');
                            textArea.style.position = 'fixed';
                            textArea.style.opacity = '0';
                            document.body.appendChild(textArea);
                            textArea.select();

                            let copied = false;

                            try {
                                copied = document.execCommand('copy');
                            } finally {
                                textArea.remove();
                            }

                            if (!copied) {
                                throw new Error('Clipboard copy was rejected.');
                            }
                        }

                        label.textContent = 'Command copied';
                        status.textContent = 'Discord command copied to your clipboard.';

                        window.setTimeout(() => {
                            label.textContent = originalLabel;
                        }, 2000);
                    } catch {
                        label.textContent = originalLabel;
                        status.textContent = 'Copy failed. Select the command above and copy it manually.';
                    }
                });
            });
        </script>
    @endpush
@endunless
