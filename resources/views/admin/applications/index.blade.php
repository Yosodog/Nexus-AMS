@extends('layouts.admin')

@section('title', 'Applications')

@section('content')
    <x-header title="Applications" separator>
        <x-slot:subtitle>Control how the bot labels applicants, where approvals are announced, and which applications need action.</x-slot:subtitle>
    </x-header>

    <x-card title="Discord & Alliance Settings" subtitle="Control how the bot labels applicants and where approvals are announced." class="mb-6">
        <x-slot:menu>
            @unless($canManage)
                <span class="badge badge-ghost">View only</span>
            @endunless
        </x-slot:menu>

        <form method="POST" action="{{ route('admin.applications.settings') }}" class="space-y-6">
            @csrf

            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                <div class="xl:col-span-1">
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
                <div>
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
                </div>
                <div>
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
                </div>
                <div>
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
                </div>
                <div>
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
                </div>
                <div>
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
                </div>
                <div>
                    <x-input
                        id="applications_discord_interview_category_id"
                        label="Interview category ID"
                        name="applications_discord_interview_category_id"
                        :value="old('applications_discord_interview_category_id', $settings['discord_interview_category_id'])"
                        error-field="applications_discord_interview_category_id"
                        popover="Discord category where the bot should create interview channels. Leave blank to let the bot choose a default."
                        hint="Use the numeric category ID (right-click and Copy ID in Discord)."
                        :disabled="! $canManage"
                        maxlength="100"
                    />
                </div>
                <div class="md:col-span-2 xl:col-span-1">
                    <x-textarea
                        id="applications_approval_message_template"
                        label="Approval message template"
                        name="applications_approval_message_template"
                        rows="3"
                        error-field="applications_approval_message_template"
                        popover="Bot announcement content posted when an applicant is approved."
                        :disabled="! $canManage"
                    >{{ old('applications_approval_message_template', $settings['approval_message_template']) }}</x-textarea>
                </div>
            </div>

            @if($canManage)
                <div class="flex justify-end">
                    <button type="submit" class="btn btn-primary">
                        Save settings
                    </button>
                </div>
            @endif
        </form>
    </x-card>

    <div class="grid gap-6">
        <x-card title="Open Applications">
            <div class="overflow-x-auto rounded-box border border-base-300">
                <table class="table table-zebra">
                    <thead>
                    <tr>
                        <th>Leader</th>
                        <th>Nation</th>
                        <th>Discord</th>
                        <th>Created</th>
                        <th>Status</th>
                        <th class="text-right">Actions</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse($openApplications as $application)
                        <tr>
                            <td>{{ $application->leader_name_snapshot }}</td>
                            <td>
                                <a href="https://politicsandwar.com/nation/id={{ $application->nation_id }}" target="_blank" rel="noopener">
                                    #{{ $application->nation_id }}
                                </a>
                            </td>
                            <td>
                                {{ $application->discord_username }}
                                <div class="text-sm text-base-content/60">{{ $application->discord_user_id }}</div>
                            </td>
                            <td>{{ $application->created_at?->diffForHumans() ?? '—' }}</td>
                            <td><span class="badge badge-warning">Pending</span></td>
                            <td class="text-right">
                                <a href="{{ route('admin.applications.show', $application) }}" class="btn btn-sm btn-outline btn-primary">
                                    View
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center text-base-content/50">No pending applications.</td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </x-card>

        <x-card title="Recent Applications">
            <div class="overflow-x-auto rounded-box border border-base-300">
                <table class="table table-zebra">
                    <thead>
                    <tr>
                        <th>Leader</th>
                        <th>Nation</th>
                        <th>Discord</th>
                        <th>Status</th>
                        <th>Updated</th>
                        <th class="text-right">View</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse($recentApplications as $application)
                        @php
                            $status = $application->status->value ?? (string) $application->status;
                            $statusClass = match($status) {
                                \App\Enums\ApplicationStatus::Approved->value => 'badge-success',
                                \App\Enums\ApplicationStatus::Denied->value => 'badge-error',
                                \App\Enums\ApplicationStatus::Cancelled->value => 'badge-ghost',
                                default => 'badge-warning'
                            };
                        @endphp
                        <tr>
                            <td>{{ $application->leader_name_snapshot }}</td>
                            <td>
                                <a href="https://politicsandwar.com/nation/id={{ $application->nation_id }}" target="_blank" rel="noopener">
                                    #{{ $application->nation_id }}
                                </a>
                            </td>
                            <td>
                                {{ $application->discord_username }}
                                <div class="text-sm text-base-content/60">{{ $application->discord_user_id }}</div>
                            </td>
                            <td><span class="badge {{ $statusClass }}">{{ ucfirst(strtolower($status)) }}</span></td>
                            <td>{{ $application->updated_at?->diffForHumans() ?? '—' }}</td>
                            <td class="text-right">
                                <a href="{{ route('admin.applications.show', $application) }}" class="btn btn-sm btn-outline">
                                    Open
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center text-base-content/50">No applications to display.</td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </x-card>
    </div>
@endsection
