@extends('layouts.admin')

@section('title', 'Applications')

@section('content')
    <header class="nexus-page-header">
        <div class="nexus-page-header__copy">
            <h1 class="nexus-page-title">Applications</h1>
            <p class="nexus-page-summary">Review the oldest pending applicants first, inspect interview context, and maintain the Discord handoff settings.</p>
        </div>
        <div class="nexus-page-header__actions">
            <span class="nexus-status {{ $settings['enabled'] ? 'nexus-status--success' : 'nexus-status--warning' }}">
                Intake {{ $settings['enabled'] ? 'open' : 'paused' }}
            </span>
            <span class="nexus-status {{ $openApplications->isEmpty() ? 'nexus-status--success' : 'nexus-status--warning' }}">
                {{ number_format($openApplications->count()) }} pending
            </span>
        </div>
    </header>

    <section class="nexus-panel nexus-panel--raised" aria-labelledby="open-applications-title">
        <div class="nexus-panel__header">
            <div>
                <h2 id="open-applications-title" class="nexus-section-title">Review queue</h2>
                <p class="nexus-body-muted mt-1">Pending applications are ordered oldest first.</p>
            </div>
            <span class="text-sm tabular-nums text-base-content/60">{{ number_format($openApplications->count()) }} open</span>
        </div>

        @forelse($openApplications as $application)
            <article class="grid gap-4 border-b border-base-300 px-5 py-4 last:border-b-0 lg:grid-cols-[minmax(0,1.2fr)_minmax(12rem,0.8fr)_auto] lg:items-center">
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <h3 class="font-semibold text-base-content">{{ $application->leader_name_snapshot }}</h3>
                        <span class="nexus-status nexus-status--warning">Pending</span>
                    </div>
                    <div class="mt-1 flex flex-wrap gap-x-3 gap-y-1 text-sm text-base-content/65">
                        <a href="https://politicsandwar.com/nation/id={{ $application->nation_id }}" target="_blank" rel="noopener" class="font-medium text-primary hover:underline">
                            Nation #{{ $application->nation_id }}
                        </a>
                        <span>Application #{{ $application->id }}</span>
                    </div>
                </div>

                <div class="min-w-0 text-sm">
                    <p class="truncate font-medium text-base-content">{{ $application->discord_username }}</p>
                    <p class="truncate text-base-content/60">Discord {{ $application->discord_user_id }}</p>
                    <p class="mt-1 text-xs text-base-content/55">
                        Submitted
                        <time datetime="{{ $application->created_at?->toIso8601String() }}" title="{{ $application->created_at?->toDayDateTimeString() }}">
                            {{ $application->created_at?->diffForHumans() ?? 'at an unknown time' }}
                        </time>
                    </p>
                </div>

                <a href="{{ route('admin.applications.show', $application) }}" class="btn btn-primary btn-sm w-full lg:w-auto">
                    Review application
                </a>
            </article>
        @empty
            <div class="nexus-empty-state">
                <x-icon name="o-check-circle" class="size-8 text-success" aria-hidden="true" />
                <div>
                    <h3 class="font-semibold">Application queue is clear</h3>
                    <p class="mt-1 text-sm text-base-content/60">There are no pending applications to review.</p>
                </div>
            </div>
        @endforelse
    </section>

    <section class="nexus-panel" aria-labelledby="recent-applications-title">
        <div class="nexus-panel__header">
            <div>
                <h2 id="recent-applications-title" class="nexus-section-title">Recent applications</h2>
                <p class="nexus-body-muted mt-1">The 25 most recently submitted applications, including completed decisions.</p>
            </div>
        </div>

        @if($recentApplications->isEmpty())
            <div class="nexus-empty-state">
                <p class="text-sm text-base-content/60">No application history is available yet.</p>
            </div>
        @else
            <div class="nexus-table-shell rounded-none border-0">
                <table class="nexus-table" data-sortable="false">
                    <thead>
                        <tr>
                            <th scope="col">Applicant</th>
                            <th scope="col">Discord</th>
                            <th scope="col">Status</th>
                            <th scope="col">Updated</th>
                            <th scope="col" data-sortable="false"><span class="sr-only">Open application</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($recentApplications as $application)
                            @php
                                $status = $application->status->value ?? (string) $application->status;
                                $statusClass = match($status) {
                                    \App\Enums\ApplicationStatus::Approved->value => 'nexus-status--success',
                                    \App\Enums\ApplicationStatus::Denied->value => 'nexus-status--error',
                                    \App\Enums\ApplicationStatus::Cancelled->value => 'nexus-status--neutral',
                                    default => 'nexus-status--warning'
                                };
                            @endphp
                            <tr>
                                <td>
                                    <span class="font-semibold">{{ $application->leader_name_snapshot }}</span>
                                    <a href="https://politicsandwar.com/nation/id={{ $application->nation_id }}" target="_blank" rel="noopener" class="block text-xs text-primary hover:underline">
                                        Nation #{{ $application->nation_id }}
                                    </a>
                                </td>
                                <td>
                                    <span class="block">{{ $application->discord_username }}</span>
                                    <span class="block text-xs text-base-content/55">{{ $application->discord_user_id }}</span>
                                </td>
                                <td><span class="nexus-status {{ $statusClass }}">{{ ucfirst(strtolower($status)) }}</span></td>
                                <td>
                                    <time datetime="{{ $application->updated_at?->toIso8601String() }}" title="{{ $application->updated_at?->toDayDateTimeString() }}">
                                        {{ $application->updated_at?->diffForHumans() ?? '—' }}
                                    </time>
                                </td>
                                <td class="text-right">
                                    <a href="{{ route('admin.applications.show', $application) }}" class="btn btn-ghost btn-sm">Open</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <details class="nexus-panel" @if($errors->any()) open @endif>
        <summary class="flex cursor-pointer list-none items-center justify-between gap-4 px-5 py-4 marker:hidden">
            <span>
                <span class="block font-semibold">Discord and alliance settings</span>
                <span class="mt-0.5 block text-sm text-base-content/60">Roles, interview routing, alliance position, and approval announcements.</span>
            </span>
            <span class="flex items-center gap-2">
                <span class="nexus-status {{ $canManage ? 'nexus-status--neutral' : 'nexus-status--warning' }}">{{ $canManage ? 'Configurable' : 'View only' }}</span>
                <x-icon name="o-chevron-down" class="size-4 text-base-content/50" aria-hidden="true" />
            </span>
        </summary>

        <form method="POST" action="{{ route('admin.applications.settings') }}" class="border-t border-base-300 p-5">
            @csrf

            <div class="nexus-form-grid">
                <div>
                    <x-toggle
                        id="applications_enabled"
                        label="Enable application system"
                        hint="Disable to temporarily pause new Discord applications."
                        name="applications_enabled"
                        value="1"
                        :disabled="! $canManage"
                        @checked(old('applications_enabled', $settings['enabled']))
                    />
                </div>
                <x-input
                    id="applications_approved_position_id"
                    label="Approved position ID"
                    name="applications_approved_position_id"
                    type="number"
                    min="0"
                    :value="old('applications_approved_position_id', $settings['approved_position_id'])"
                    error-field="applications_approved_position_id"
                    popover="Politics & War alliance position ID for fully approved members. The bot assigns this after approval."
                    :disabled="! $canManage"
                    required
                />
                <x-input
                    id="applications_discord_applicant_role_id"
                    label="Applicant role ID"
                    name="applications_discord_applicant_role_id"
                    :value="old('applications_discord_applicant_role_id', $settings['discord_applicant_role_id'])"
                    error-field="applications_discord_applicant_role_id"
                    popover="Discord role applied when someone starts an application. Used to gate interview channels."
                    :disabled="! $canManage"
                    maxlength="100"
                />
                <x-input
                    id="applications_discord_ia_role_id"
                    label="IA role ID"
                    name="applications_discord_ia_role_id"
                    :value="old('applications_discord_ia_role_id', $settings['discord_ia_role_id'])"
                    error-field="applications_discord_ia_role_id"
                    popover="Discord role granted to IA reviewers so the bot can grant channel access and tag staff."
                    :disabled="! $canManage"
                    maxlength="100"
                />
                <x-input
                    id="applications_discord_member_role_id"
                    label="Member role ID"
                    name="applications_discord_member_role_id"
                    :value="old('applications_discord_member_role_id', $settings['discord_member_role_id'])"
                    error-field="applications_discord_member_role_id"
                    popover="Discord role assigned after approval so members get the right server access."
                    :disabled="! $canManage"
                    maxlength="100"
                />
                <x-input
                    id="applications_approval_announcement_channel_id"
                    label="Approval announcement channel ID"
                    name="applications_approval_announcement_channel_id"
                    :value="old('applications_approval_announcement_channel_id', $settings['approval_announcement_channel_id'])"
                    error-field="applications_approval_announcement_channel_id"
                    popover="Channel where the bot announces approved applicants. Leave blank to skip announcements."
                    :disabled="! $canManage"
                    maxlength="100"
                />
                <x-input
                    id="applications_discord_interview_category_id"
                    label="Interview category ID"
                    name="applications_discord_interview_category_id"
                    :value="old('applications_discord_interview_category_id', $settings['discord_interview_category_id'])"
                    error-field="applications_discord_interview_category_id"
                    popover="Discord category where the bot should create interview channels. Leave blank to let the bot choose a default."
                    hint="Use the numeric category ID from Discord."
                    :disabled="! $canManage"
                    maxlength="100"
                />
                <div class="md:col-span-2">
                    <x-textarea
                        id="applications_approval_message_template"
                        label="Approval message template"
                        name="applications_approval_message_template"
                        rows="4"
                        error-field="applications_approval_message_template"
                        popover="Bot announcement content posted when an applicant is approved."
                        :disabled="! $canManage"
                    >{{ old('applications_approval_message_template', $settings['approval_message_template']) }}</x-textarea>
                </div>
            </div>

            @if($canManage)
                <div class="nexus-form-actions mt-5">
                    <button type="submit" class="btn btn-primary">Save application settings</button>
                </div>
            @endif
        </form>
    </details>
@endsection
