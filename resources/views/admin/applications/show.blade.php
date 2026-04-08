@extends('layouts.admin')

@section('title', 'Application #'.$application->id)

@section('content')
    @php
        $statusValue = $application->status->value ?? (string) $application->status;
        $statusClass = match($statusValue) {
            \App\Enums\ApplicationStatus::Approved->value => 'badge-success',
            \App\Enums\ApplicationStatus::Denied->value => 'badge-error',
            \App\Enums\ApplicationStatus::Cancelled->value => 'badge-ghost',
            default => 'badge-warning'
        };
    @endphp

    <x-header :title="'Application #' . $application->id" separator>
        <x-slot:subtitle>Nation #{{ $application->nation_id }} · {{ $application->leader_name_snapshot }}</x-slot:subtitle>
        <x-slot:actions>
            <div class="flex flex-wrap items-center gap-2">
                <span class="badge {{ $statusClass }} badge-lg">{{ ucfirst(strtolower($statusValue)) }}</span>
                @can('manage-applications')
                    @if($application->status === \App\Enums\ApplicationStatus::Pending)
                        <form action="{{ route('admin.applications.cancel', $application) }}" method="POST">
                            @csrf
                            <button
                                type="submit"
                                class="btn btn-error btn-outline btn-sm"
                                onclick="return confirm('Cancel this application?')"
                            >
                                Cancel
                            </button>
                        </form>
                    @endif
                @endcan
            </div>
        </x-slot:actions>
    </x-header>

    <div class="grid gap-6 xl:grid-cols-[minmax(0,22rem)_minmax(0,1fr)]">
        <div class="space-y-6">
            <x-card title="Applicant">
                <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-3 text-sm">
                    <dt class="font-semibold text-base-content/70">Leader</dt>
                    <dd>{{ $application->leader_name_snapshot }}</dd>

                    <dt class="font-semibold text-base-content/70">Nation</dt>
                    <dd>
                        <a href="https://politicsandwar.com/nation/id={{ $application->nation_id }}" target="_blank" rel="noopener" class="link link-primary">
                            #{{ $application->nation_id }}
                        </a>
                    </dd>

                    <dt class="font-semibold text-base-content/70">Discord</dt>
                    <dd>
                        <div>{{ $application->discord_username }}</div>
                        <div class="text-base-content/60">{{ $application->discord_user_id }}</div>
                    </dd>

                    <dt class="font-semibold text-base-content/70">Channel</dt>
                    <dd>{{ $application->discord_channel_id ?? '—' }}</dd>

                    <dt class="font-semibold text-base-content/70">Created</dt>
                    <dd>{{ $application->created_at?->toDayDateTimeString() ?? '—' }}</dd>
                </dl>
            </x-card>

            <x-card title="Status">
                <div class="space-y-4 text-sm">
                    <div class="flex items-center justify-between gap-3">
                        <span class="font-semibold text-base-content/70">Current</span>
                        <span class="badge {{ $statusClass }}">{{ ucfirst(strtolower($statusValue)) }}</span>
                    </div>
                    <div>
                        <div class="font-semibold text-base-content/70">Approved</div>
                        <div>{{ $application->approved_at?->toDayDateTimeString() ?? '—' }}</div>
                        @if($application->approved_by_discord_id)
                            <div class="text-base-content/60">by {{ $application->approved_by_discord_id }}</div>
                        @endif
                    </div>
                    <div>
                        <div class="font-semibold text-base-content/70">Denied</div>
                        <div>{{ $application->denied_at?->toDayDateTimeString() ?? '—' }}</div>
                        @if($application->denied_by_discord_id)
                            <div class="text-base-content/60">by {{ $application->denied_by_discord_id }}</div>
                        @endif
                    </div>
                    <div>
                        <div class="font-semibold text-base-content/70">Cancelled</div>
                        <div>{{ $application->cancelled_at?->toDayDateTimeString() ?? '—' }}</div>
                        @if($application->cancelled_by_discord_id)
                            <div class="text-base-content/60">by {{ $application->cancelled_by_discord_id }}</div>
                        @endif
                    </div>
                </div>
            </x-card>
        </div>

        <x-card title="Interview Transcript" :subtitle="$application->messages->count() . ' messages'">
            <div class="space-y-4">
                @forelse($application->messages as $message)
                    <article class="rounded-box border border-base-300 bg-base-100 p-4 shadow-sm {{ $message->is_staff ? 'ring-1 ring-info/20 bg-info/5' : '' }}">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <div class="font-semibold">{{ $message->discord_username }}</div>
                                <div class="text-sm text-base-content/60">{{ $message->discord_user_id }}</div>
                            </div>
                            <div class="text-sm text-base-content/60">{{ $message->sent_at?->toDayDateTimeString() ?? '—' }}</div>
                        </div>
                        @if($message->is_staff)
                            <span class="badge badge-info mt-3">Staff</span>
                        @endif
                        <div class="mt-3 whitespace-pre-line text-sm leading-6">{!! nl2br(e($message->content)) !!}</div>
                    </article>
                @empty
                    <div class="alert alert-info">
                        <x-icon name="o-information-circle" class="size-5" />
                        <span>No messages have been logged for this application yet.</span>
                    </div>
                @endforelse
            </div>
        </x-card>
    </div>
@endsection
