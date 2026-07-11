@extends('layouts.main')

@section('content')
    <div class="mx-auto w-full max-w-7xl space-y-8">
        <header class="nexus-page-header">
            <div class="nexus-page-header__copy">
                <p class="nexus-kicker">Member guide</p>
                <h1 class="nexus-page-title">Discord bot guide</h1>
                <p class="nexus-page-summary">
                    Use {{ $appName }} accounts, assistance programs, defense tools, and application workflows directly from the alliance Discord server. This guide covers every available command and what happens after you run it.
                </p>
            </div>
            <div class="nexus-page-header__actions">
                <a href="{{ route('user.settings') }}" class="btn btn-outline btn-sm">
                    <x-icon name="o-cog-6-tooth" class="size-4" />
                    Discord settings
                </a>
            </div>
        </header>

        <section class="nexus-panel overflow-hidden" aria-labelledby="quick-start-heading">
            <div class="nexus-panel__header">
                <div class="max-w-2xl">
                    <h2 id="quick-start-heading" class="nexus-section-title">Before your first command</h2>
                    <p class="mt-2 text-sm leading-6 text-base-content/70">Commands run only in the configured alliance server. The bot resolves your {{ $appName }} identity from your linked Discord account.</p>
                </div>
                <span class="nexus-status nexus-status--success">Private responses</span>
            </div>
            <ol class="divide-y divide-base-300 sm:grid sm:grid-cols-3 sm:divide-x sm:divide-y-0">
                <li class="flex gap-3 p-5">
                    <span class="grid size-7 shrink-0 place-items-center rounded-full bg-primary text-sm font-bold text-primary-content">1</span>
                    <div>
                        <h3 class="font-semibold">Link Discord</h3>
                        <p class="mt-1 text-sm leading-6 text-base-content/70">Generate a code in {{ $appName }} Settings and run <code class="font-mono text-xs">/verify</code>.</p>
                    </div>
                </li>
                <li class="flex gap-3 p-5">
                    <span class="grid size-7 shrink-0 place-items-center rounded-full bg-primary text-sm font-bold text-primary-content">2</span>
                    <div>
                        <h3 class="font-semibold">Choose a command</h3>
                        <p class="mt-1 text-sm leading-6 text-base-content/70">Type <code class="font-mono text-xs">/</code> in Discord and select a {{ $appName }} command from autocomplete.</p>
                    </div>
                </li>
                <li class="flex gap-3 p-5">
                    <span class="grid size-7 shrink-0 place-items-center rounded-full bg-primary text-sm font-bold text-primary-content">3</span>
                    <div>
                        <h3 class="font-semibold">Review before confirming</h3>
                        <p class="mt-1 text-sm leading-6 text-base-content/70">Money-moving and request commands show a private preview before making a change.</p>
                    </div>
                </li>
            </ol>
        </section>

        <div class="grid items-start gap-8 lg:grid-cols-[15rem_minmax(0,1fr)]">
            <aside class="nexus-panel p-4 lg:sticky lg:top-28" aria-label="Guide sections">
                <p class="mb-3 text-sm font-semibold">On this page</p>
                <nav class="grid gap-1 text-sm">
                    @foreach($commandGroups as $group)
                        <a href="#{{ $group['id'] }}" class="flex min-h-10 items-center gap-2 rounded-md px-3 py-2 text-base-content/75 transition-colors hover:bg-base-200 hover:text-base-content focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">
                            <x-icon :name="$group['icon']" class="size-4 shrink-0" />
                            {{ $group['title'] }}
                        </a>
                    @endforeach
                    <a href="#notifications" class="flex min-h-10 items-center gap-2 rounded-md px-3 py-2 text-base-content/75 transition-colors hover:bg-base-200 hover:text-base-content focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">
                        <x-icon name="o-bell" class="size-4 shrink-0" />
                        Private notifications
                    </a>
                    <a href="#automations" class="flex min-h-10 items-center gap-2 rounded-md px-3 py-2 text-base-content/75 transition-colors hover:bg-base-200 hover:text-base-content focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">
                        <x-icon name="o-bolt" class="size-4 shrink-0" />
                        Automatic features
                    </a>
                    <a href="#staff" class="flex min-h-10 items-center gap-2 rounded-md px-3 py-2 text-base-content/75 transition-colors hover:bg-base-200 hover:text-base-content focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">
                        <x-icon name="o-lock-closed" class="size-4 shrink-0" />
                        Staff commands
                    </a>
                </nav>
            </aside>

            <div class="min-w-0 space-y-8">
                @foreach($commandGroups as $group)
                    <section id="{{ $group['id'] }}" class="scroll-mt-28" aria-labelledby="{{ $group['id'] }}-heading">
                        <div class="mb-4 flex items-start gap-3">
                            <span class="grid size-10 shrink-0 place-items-center rounded-lg bg-base-300 text-base-content">
                                <x-icon :name="$group['icon']" class="size-5" />
                            </span>
                            <div class="max-w-3xl">
                                <h2 id="{{ $group['id'] }}-heading" class="nexus-section-title">{{ $group['title'] }}</h2>
                                <p class="mt-1 text-sm leading-6 text-base-content/70">{{ $group['summary'] }}</p>
                            </div>
                        </div>

                        <div class="nexus-panel divide-y divide-base-300 overflow-hidden">
                            @foreach($group['commands'] as $command)
                                <article class="p-4 sm:p-5">
                                    <code class="inline-block max-w-full break-words rounded-md bg-base-200 px-2.5 py-1.5 font-mono text-sm font-semibold text-base-content">{{ $command['command'] }}</code>
                                    <p class="mt-3 max-w-3xl text-sm leading-6 text-base-content/80">{{ $command['description'] }}</p>

                                    @if(isset($command['usage']))
                                        <ul class="mt-3 max-w-3xl space-y-1.5 text-sm leading-6 text-base-content/70">
                                            @foreach($command['usage'] as $step)
                                                <li class="flex gap-2">
                                                    <x-icon name="o-check" class="mt-1 size-4 shrink-0 text-success" />
                                                    <span>{{ $step }}</span>
                                                </li>
                                            @endforeach
                                        </ul>
                                    @endif

                                    @if(isset($command['note']))
                                        <p class="mt-3 max-w-3xl rounded-md bg-info/10 px-3 py-2 text-sm leading-6 text-base-content/80">
                                            <span class="font-semibold">Good to know:</span> {{ $command['note'] }}
                                        </p>
                                    @endif
                                </article>
                            @endforeach
                        </div>
                    </section>
                @endforeach

                <section id="notifications" class="scroll-mt-28" aria-labelledby="notifications-heading">
                    <div class="mb-4 flex items-start gap-3">
                        <span class="grid size-10 shrink-0 place-items-center rounded-lg bg-base-300 text-base-content">
                            <x-icon name="o-bell" class="size-5" />
                        </span>
                        <div class="max-w-3xl">
                            <h2 id="notifications-heading" class="nexus-section-title">Private notifications</h2>
                            <p class="mt-1 text-sm leading-6 text-base-content/70">{{ $appName }} can send direct-message updates without exposing workflow details in a public channel.</p>
                        </div>
                    </div>

                    <div class="nexus-panel p-5 sm:p-6">
                        <div class="grid gap-6 md:grid-cols-2">
                            <div>
                                <h3 class="font-semibold">Available updates</h3>
                                <ul class="mt-3 space-y-2 text-sm leading-6 text-base-content/75">
                                    <li>Application decisions</li>
                                    <li>Grant and city-grant status</li>
                                    <li>Loan status</li>
                                    <li>War-aid and rebuilding status</li>
                                    <li>War and spy assignments</li>
                                </ul>
                            </div>
                            <div>
                                <h3 class="font-semibold">Privacy controls</h3>
                                <p class="mt-3 text-sm leading-6 text-base-content/75">Notifications are off by default, sent only by DM, and never fall back to a public channel. Alliance administrators control the master switch, and you can opt in to individual categories in {{ $appName }} Settings.</p>
                                <a href="{{ route('user.settings') }}" class="btn btn-outline btn-sm mt-4">Manage notification preferences</a>
                            </div>
                        </div>
                    </div>
                </section>

                <section id="automations" class="scroll-mt-28" aria-labelledby="automations-heading">
                    <div class="mb-4 flex items-start gap-3">
                        <span class="grid size-10 shrink-0 place-items-center rounded-lg bg-base-300 text-base-content">
                            <x-icon name="o-bolt" class="size-5" />
                        </span>
                        <div class="max-w-3xl">
                            <h2 id="automations-heading" class="nexus-section-title">Automatic Discord features</h2>
                            <p class="mt-1 text-sm leading-6 text-base-content/70">Some bot features react to {{ $appName }} events or messages instead of a slash command.</p>
                        </div>
                    </div>

                    <div class="nexus-panel divide-y divide-base-300 overflow-hidden">
                        @foreach($automations as $automation)
                            <article class="p-4 sm:p-5">
                                <h3 class="font-semibold">{{ $automation['title'] }}</h3>
                                <p class="mt-2 max-w-3xl text-sm leading-6 text-base-content/80">{{ $automation['description'] }}</p>
                                <p class="mt-2 max-w-3xl text-sm leading-6 text-base-content/70">
                                    <span class="font-semibold text-base-content">How to use it:</span> {{ $automation['action'] }}
                                </p>
                            </article>
                        @endforeach
                    </div>
                </section>

                <section id="staff" class="scroll-mt-28" aria-labelledby="staff-heading">
                    <div class="mb-4 flex items-start gap-3">
                        <span class="grid size-10 shrink-0 place-items-center rounded-lg bg-warning/20 text-warning-content">
                            <x-icon name="o-lock-closed" class="size-5" />
                        </span>
                        <div class="max-w-3xl">
                            <div class="flex flex-wrap items-center gap-2">
                                <h2 id="staff-heading" class="nexus-section-title">Staff commands</h2>
                                <span class="badge badge-warning badge-outline">Permission required</span>
                            </div>
                            <p class="mt-1 text-sm leading-6 text-base-content/70">These commands are visible in Discord, but {{ $appName }} performs the final permission and workflow-state checks.</p>
                        </div>
                    </div>

                    <div class="nexus-panel divide-y divide-base-300 overflow-hidden">
                        @foreach($staffCommands as $command)
                            <article class="p-4 sm:p-5">
                                <code class="inline-block max-w-full break-words rounded-md bg-base-200 px-2.5 py-1.5 font-mono text-sm font-semibold text-base-content">{{ $command['command'] }}</code>
                                <p class="mt-3 max-w-3xl text-sm leading-6 text-base-content/80">{{ $command['description'] }}</p>
                                @if(isset($command['note']))
                                    <p class="mt-3 max-w-3xl rounded-md bg-warning/10 px-3 py-2 text-sm leading-6 text-base-content/80">
                                        <span class="font-semibold">Safety:</span> {{ $command['note'] }}
                                    </p>
                                @endif
                            </article>
                        @endforeach
                    </div>
                </section>

                <section class="nexus-panel bg-base-200 p-5 sm:p-6" aria-labelledby="safety-heading">
                    <div class="flex items-start gap-3">
                        <x-icon name="o-shield-check" class="mt-0.5 size-6 shrink-0 text-success" />
                        <div class="max-w-3xl">
                            <h2 id="safety-heading" class="text-lg font-semibold">How {{ $appName }} protects command actions</h2>
                            <p class="mt-2 text-sm leading-6 text-base-content/75">Discord does not decide account ownership, permissions, balances, or eligibility. {{ $appName }} authenticates the linked user, validates every request again at confirmation, and uses expiring confirmation tokens and idempotency safeguards for state-changing actions. If a result looks wrong, stop and contact staff instead of repeating the command.</p>
                        </div>
                    </div>
                </section>
            </div>
        </div>
    </div>
@endsection
