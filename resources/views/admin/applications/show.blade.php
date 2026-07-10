@extends('layouts.admin')

@section('title', 'Application #'.$application->id)

@section('content')
    @php
        $statusValue = $application->status->value ?? (string) $application->status;
        $statusClass = match($statusValue) {
            \App\Enums\ApplicationStatus::Approved->value => 'nexus-status--success',
            \App\Enums\ApplicationStatus::Denied->value => 'nexus-status--error',
            \App\Enums\ApplicationStatus::Cancelled->value => 'nexus-status--neutral',
            default => 'nexus-status--warning'
        };
    @endphp

    <header class="nexus-page-header">
        <div class="nexus-page-header__copy">
            <a href="{{ route('admin.applications.index') }}" class="mb-1 inline-flex w-fit items-center gap-1 text-sm font-semibold text-primary hover:underline">
                <x-icon name="o-arrow-left" class="size-4" aria-hidden="true" />
                Applications
            </a>
            <h1 class="nexus-page-title">Application #{{ $application->id }}</h1>
            <p class="nexus-page-summary">
                {{ $application->leader_name_snapshot }} · Nation #{{ $application->nation_id }} · submitted {{ $application->created_at?->diffForHumans() ?? 'at an unknown time' }}
            </p>
        </div>
        <div class="nexus-page-header__actions">
            <span class="nexus-status {{ $statusClass }}">{{ ucfirst(strtolower($statusValue)) }}</span>
            @can('manage-applications')
                @if($application->status === \App\Enums\ApplicationStatus::Pending)
                    <form
                        action="{{ route('admin.applications.cancel', $application) }}"
                        method="POST"
                        data-confirm="Cancel this pending application? This removes it from the queue and records you as the actor."
                        data-confirm-title="Cancel application?"
                        data-confirm-label="Cancel application"
                        data-confirm-tone="error"
                    >
                        @csrf
                        <button type="submit" class="btn btn-error btn-outline btn-sm">Cancel application</button>
                    </form>
                @endif
            @endcan
        </div>
    </header>

    <div class="grid gap-6 xl:grid-cols-[minmax(18rem,0.72fr)_minmax(0,1.28fr)]">
        <div class="space-y-6">
            <section class="nexus-panel" aria-labelledby="applicant-context-title">
                <div class="nexus-panel__header">
                    <h2 id="applicant-context-title" class="nexus-section-title">Applicant context</h2>
                </div>
                <dl class="divide-y divide-base-300 text-sm">
                    <div class="grid grid-cols-[7rem_minmax(0,1fr)] gap-3 px-5 py-3">
                        <dt class="text-base-content/60">Leader</dt>
                        <dd class="font-semibold">{{ $application->leader_name_snapshot }}</dd>
                    </div>
                    <div class="grid grid-cols-[7rem_minmax(0,1fr)] gap-3 px-5 py-3">
                        <dt class="text-base-content/60">Nation</dt>
                        <dd>
                            <a href="https://politicsandwar.com/nation/id={{ $application->nation_id }}" target="_blank" rel="noopener" class="font-semibold text-primary hover:underline">
                                Nation #{{ $application->nation_id }}
                            </a>
                        </dd>
                    </div>
                    <div class="grid grid-cols-[7rem_minmax(0,1fr)] gap-3 px-5 py-3">
                        <dt class="text-base-content/60">Discord</dt>
                        <dd class="min-w-0">
                            <span class="block truncate font-semibold">{{ $application->discord_username }}</span>
                            <span class="block truncate text-base-content/60">{{ $application->discord_user_id }}</span>
                        </dd>
                    </div>
                    <div class="grid grid-cols-[7rem_minmax(0,1fr)] gap-3 px-5 py-3">
                        <dt class="text-base-content/60">Channel</dt>
                        <dd class="break-all font-mono text-xs">{{ $application->discord_channel_id ?? 'Not recorded' }}</dd>
                    </div>
                </dl>
            </section>

            <section class="nexus-panel" aria-labelledby="application-history-title">
                <div class="nexus-panel__header">
                    <h2 id="application-history-title" class="nexus-section-title">Decision history</h2>
                </div>
                <ol class="divide-y divide-base-300 text-sm">
                    <li class="px-5 py-3">
                        <span class="block font-semibold">Submitted</span>
                        <time class="text-base-content/60" datetime="{{ $application->created_at?->toIso8601String() }}">
                            {{ $application->created_at?->toDayDateTimeString() ?? 'Unknown' }}
                        </time>
                    </li>
                    @if($application->approved_at)
                        <li class="px-5 py-3">
                            <span class="block font-semibold text-success">Approved</span>
                            <time class="text-base-content/60" datetime="{{ $application->approved_at->toIso8601String() }}">{{ $application->approved_at->toDayDateTimeString() }}</time>
                            @if($application->approved_by_discord_id)
                                <span class="block text-base-content/60">Actor Discord ID {{ $application->approved_by_discord_id }}</span>
                            @endif
                        </li>
                    @endif
                    @if($application->denied_at)
                        <li class="px-5 py-3">
                            <span class="block font-semibold text-error">Denied</span>
                            <time class="text-base-content/60" datetime="{{ $application->denied_at->toIso8601String() }}">{{ $application->denied_at->toDayDateTimeString() }}</time>
                            @if($application->denied_by_discord_id)
                                <span class="block text-base-content/60">Actor Discord ID {{ $application->denied_by_discord_id }}</span>
                            @endif
                        </li>
                    @endif
                    @if($application->cancelled_at)
                        <li class="px-5 py-3">
                            <span class="block font-semibold">Cancelled</span>
                            <time class="text-base-content/60" datetime="{{ $application->cancelled_at->toIso8601String() }}">{{ $application->cancelled_at->toDayDateTimeString() }}</time>
                            @if($application->cancelled_by_discord_id)
                                <span class="block text-base-content/60">Actor Discord ID {{ $application->cancelled_by_discord_id }}</span>
                            @endif
                        </li>
                    @endif
                    @if(! $application->approved_at && ! $application->denied_at && ! $application->cancelled_at)
                        <li class="px-5 py-3 text-base-content/60">Awaiting a decision through the configured application workflow.</li>
                    @endif
                </ol>
            </section>
        </div>

        <section class="nexus-panel min-w-0" aria-labelledby="interview-transcript-title">
            <div class="nexus-panel__header">
                <div>
                    <h2 id="interview-transcript-title" class="nexus-section-title">Interview transcript</h2>
                    <p class="nexus-body-muted mt-1">{{ number_format($application->messages->count()) }} logged messages in chronological order.</p>
                </div>
            </div>

            @forelse($application->messages as $message)
                <article class="border-b border-base-300 px-5 py-4 last:border-b-0 {{ $message->is_staff ? 'bg-info/5' : '' }}">
                    <header class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <h3 class="font-semibold">{{ $message->discord_username }}</h3>
                                @if($message->is_staff)
                                    <span class="nexus-status nexus-status--info">Staff</span>
                                @endif
                            </div>
                            <p class="truncate text-xs text-base-content/55">Discord {{ $message->discord_user_id }}</p>
                        </div>
                        <time class="text-xs text-base-content/60" datetime="{{ $message->sent_at?->toIso8601String() }}" title="{{ $message->sent_at?->toDayDateTimeString() }}">
                            {{ $message->sent_at?->diffForHumans() ?? 'Unknown time' }}
                        </time>
                    </header>
                    <div class="mt-3 whitespace-pre-line text-sm leading-6 text-base-content/85">{!! nl2br(e($message->content)) !!}</div>
                </article>
            @empty
                <div class="nexus-empty-state">
                    <x-icon name="o-chat-bubble-left-right" class="size-8 text-base-content/40" aria-hidden="true" />
                    <div>
                        <h3 class="font-semibold">No transcript recorded</h3>
                        <p class="mt-1 text-sm text-base-content/60">No interview messages have been logged for this application.</p>
                    </div>
                </div>
            @endforelse
        </section>
    </div>
@endsection
