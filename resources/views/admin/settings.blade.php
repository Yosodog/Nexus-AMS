@extends('layouts.admin')

@section('content')
    <x-header title="Admin Settings" separator>
        <x-slot:subtitle>Control sync workflows, diagnostics, public-facing content, and operational toggles from one place.</x-slot:subtitle>
    </x-header>

    <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="text-lg font-semibold">Data Synchronization</h2>
            <p class="text-sm text-base-content/60">Review manual sync controls, rolling progress, and operational status before forcing jobs.</p>
        </div>
    </div>

    <details class="collapse collapse-arrow mb-6 border border-base-300 bg-base-100">
        <summary class="collapse-title text-sm font-semibold">How the sync system works</summary>
        <div class="collapse-content">
            <div class="alert alert-info">
                <div class="space-y-3 text-sm leading-6">
                    <p>{{ config('app.name') }} typically keeps nation, alliance, and war data updated in near real-time using live subscriptions to the Politics & War API.</p>
                    <p>Full sync jobs are automatically scheduled and run periodically, so manual execution is rarely needed. Manual sync should only be used to correct known discrepancies.</p>
                    <p>The <strong>Manual Nation Sync</strong> runs immediately and cancels any in-progress rolling nation sync. The <strong>Rolling Nation Sync</strong> is queued by the scheduler and staggers the workload across roughly 23 hours.</p>
                    <p>Each sync fetches and updates all data for the selected type. Depending on queue activity, this can take anywhere from a few minutes to nearly an hour.</p>
                    <p><strong>Note:</strong> Running syncs consumes queue capacity and may delay other time-sensitive tasks like withdrawals, transfers, and in-game messaging.</p>
                </div>
            </div>
        </div>
    </details>

    <div class="mb-6 grid gap-6 md:grid-cols-2">
        @include('components.admin.sync-card', [
            'title' => 'Nation Sync (Manual)',
            'batch' => $nationBatch,
            'route' => route('admin.settings.sync.run'),
        ])

        @include('components.admin.rolling-sync-card', [
            'batch' => $rollingNationBatch,
            'rollingSchedule' => $rollingSchedule,
        ])

        @include('components.admin.sync-card', [
            'title' => 'Alliance Sync',
            'batch' => $allianceBatch,
            'route' => route('admin.settings.sync.alliances'),
        ])

        @include('components.admin.sync-card', [
            'title' => 'War Sync',
            'batch' => $warBatch,
            'route' => route('admin.settings.sync.wars'),
        ])
    </div>

    <div class="mb-4">
        <h2 class="text-lg font-semibold">Other Settings</h2>
        <p class="text-sm text-base-content/60">Operational controls, diagnostics, and public site content.</p>
    </div>

    <div class="grid gap-6 lg:grid-cols-2">
        <x-card title="Pending Request Recovery" subtitle="Use this only when a workflow is genuinely stuck." class="lg:col-span-2 border border-warning/40">
            <x-slot:menu>
                <span class="badge badge-warning">Diagnostics</span>
            </x-slot:menu>

            <div class="space-y-4">
                <div class="alert alert-warning">
                    <span class="text-sm">These actions do not approve anything. They force stale pending rows into a terminal state such as denied, cancelled, or expired.</span>
                </div>

                <div class="overflow-x-auto rounded-box border border-base-300">
                    <table class="table table-zebra">
                        <thead>
                        <tr>
                            <th scope="col">Workflow</th>
                            <th scope="col">Pending</th>
                            <th scope="col">Stale ({{ $stalePendingDefaultHours }}h+)</th>
                            <th scope="col">Oldest Pending</th>
                            <th scope="col">Force Release</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach($pendingRecoveryItems as $item)
                            <tr>
                                <td class="font-semibold">{{ $item['label'] }}</td>
                                <td>{{ number_format($item['totalPending']) }}</td>
                                <td>
                                    <span class="badge {{ $item['stalePending'] > 0 ? 'badge-warning' : 'badge-ghost' }}">
                                        {{ number_format($item['stalePending']) }}
                                    </span>
                                </td>
                                <td>
                                    @if($item['oldestCreatedAt'])
                                        <div>{{ $item['oldestCreatedAt']->format('M d, Y H:i') }}</div>
                                        <div class="text-sm text-base-content/60">{{ $item['oldestCreatedAt']->diffForHumans() }}</div>
                                    @else
                                        <span class="text-sm text-base-content/60">None</span>
                                    @endif
                                </td>
                                <td>
                                    <form method="POST" action="{{ route('admin.settings.pending-requests.release-stale') }}" class="flex flex-wrap items-end gap-2">
                                        @csrf
                                        <input type="hidden" name="type" value="{{ $item['type'] }}">
                                        <label class="block space-y-2">
                                            <span class="text-xs font-medium">Older than (hours)</span>
                                            <input
                                                type="number"
                                                class="input input-bordered input-sm w-28"
                                                id="olderThanHours-{{ $item['type'] }}"
                                                name="older_than_hours"
                                                min="1"
                                                max="8760"
                                                value="{{ old('older_than_hours', $stalePendingDefaultHours) }}"
                                                required
                                            >
                                        </label>
                                        <button class="btn btn-warning btn-outline btn-sm" type="submit">Release Stale</button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </x-card>

        @php
            $highlightInputs = old('home_highlights', $homepageSettings['highlights'] ?? []);
            $highlightInputs = array_pad($highlightInputs, 3, '');
        @endphp
        <x-card title="Homepage Messaging" subtitle="Edit the public homepage copy for your alliance.">
            <x-slot:menu>
                <span class="badge badge-info">Public</span>
            </x-slot:menu>

            <form method="POST" action="{{ route('admin.settings.homepage') }}" class="space-y-4">
                @csrf
                <label class="block space-y-2">
                    <span class="text-sm font-medium">Headline</span>
                    <input type="text" class="input input-bordered" id="homeHeadline" name="home_headline" value="{{ old('home_headline', $homepageSettings['headline'] ?? '') }}" maxlength="160" required>
                </label>

                <label class="block space-y-2">
                    <span class="text-sm font-medium">Tagline</span>
                    <input type="text" class="input input-bordered" id="homeTagline" name="home_tagline" value="{{ old('home_tagline', $homepageSettings['tagline'] ?? '') }}" maxlength="240" required>
                </label>

                <label class="block space-y-2">
                    <span class="text-sm font-medium">About blurb</span>
                    <textarea class="textarea textarea-bordered min-h-28" id="homeAbout" name="home_about" maxlength="800" placeholder="Short paragraph for guests">{{ old('home_about', $homepageSettings['about'] ?? '') }}</textarea>
                    <span class="text-xs text-base-content/60">A short paragraph near the top of the page.</span>
                </label>

                <label class="block space-y-2">
                    <span class="text-sm font-medium">Stats intro</span>
                    <input type="text" class="input input-bordered" id="homeStatsIntro" name="home_stats_intro" value="{{ old('home_stats_intro', $homepageSettings['stats_intro'] ?? '') }}" maxlength="240" placeholder="A quick look at the alliance as it stands today.">
                </label>

                <label class="block space-y-2">
                    <span class="text-sm font-medium">Closing line</span>
                    <input type="text" class="input input-bordered" id="homeClosingText" name="home_closing_text" value="{{ old('home_closing_text', $homepageSettings['closing_text'] ?? '') }}" maxlength="300" placeholder="If this feels like the right fit, send in your application and come meet the team.">
                </label>

                <label class="block space-y-2">
                    <span class="text-sm font-medium">Hero badge</span>
                    <input type="text" class="input input-bordered" id="homeHeroBadge" name="home_hero_badge" value="{{ old('home_hero_badge', $homepageSettings['hero_badge'] ?? '') }}" maxlength="60" placeholder="Recruiting now">
                    <span class="block text-xs text-base-content/60">Short status label shown in the hero section.</span>
                </label>

                <label class="block space-y-2">
                    <span class="text-sm font-medium">CTA button label</span>
                    <input type="text" class="input input-bordered" id="homeCtaLabel" name="home_cta_label" value="{{ old('home_cta_label', $homepageSettings['cta_label'] ?? '') }}" maxlength="60" placeholder="Start your application">
                    <span class="block text-xs text-base-content/60">Text on the main call-to-action button.</span>
                </label>

                <div class="space-y-2">
                    <div class="text-sm font-medium">Highlights (optional)</div>
                    @foreach($highlightInputs as $highlight)
                        <input type="text" class="input input-bordered w-full" name="home_highlights[]" value="{{ $highlight }}" maxlength="140" placeholder="e.g. Clear onboarding and quick responses">
                    @endforeach
                    <div class="text-xs text-base-content/60">Short points that tell recruits what they can expect.</div>
                </div>

                <div class="pt-2">
                    <button class="btn btn-primary" type="submit">Save Homepage Content</button>
                </div>
            </form>
        </x-card>

        @php
            $canUploadFavicon = auth()->user()?->can('view-diagnostic-info') ?? false;
        @endphp
        <x-card title="Favicon" subtitle="Upload a square icon to update the browser favicon across the site." class="{{ $canUploadFavicon ? '' : 'opacity-60' }}">
            <x-slot:menu>
                <span class="badge badge-ghost">Branding</span>
            </x-slot:menu>

            <div class="space-y-4">
                <div class="flex items-center gap-4">
                    <div class="flex h-14 w-14 items-center justify-center rounded-box border border-base-300 bg-white">
                        <img src="{{ $faviconUrl }}" alt="Current favicon" class="max-h-8 max-w-8">
                    </div>
                    <div class="text-sm text-base-content/60">Current favicon preview</div>
                </div>

                <form method="POST" action="{{ route('admin.settings.favicon') }}" enctype="multipart/form-data" class="space-y-4">
                    @csrf
                    <label class="block space-y-2">
                        <span class="text-sm font-medium">Favicon file</span>
                        <input
                            type="file"
                            class="file-input file-input-bordered"
                            id="faviconUpload"
                            name="favicon"
                            accept=".png,.ico,.svg,.jpg,.jpeg"
                            @disabled(! $canUploadFavicon)
                            required
                        >
                        <span class="text-xs text-base-content/60">Recommended: 32x32 or 64x64.</span>
                    </label>

                    <div class="pt-2">
                        <button class="btn btn-primary" @disabled(! $canUploadFavicon)>Upload Favicon</button>
                    </div>

                    @if (! $canUploadFavicon)
                        <div class="text-sm text-base-content/60">Requires the View Diagnostic permission.</div>
                    @endif
                </form>
            </div>
        </x-card>

        <x-card title="Discord Verification" subtitle="Redirect users without an active Discord link after in-game verification.">
            <x-slot:menu>
                <span class="badge {{ $discordVerificationRequired ? 'badge-success' : 'badge-ghost' }}">
                    {{ $discordVerificationRequired ? 'Required' : 'Optional' }}
                </span>
            </x-slot:menu>

            <form method="POST" action="{{ route('admin.settings.discord') }}" class="space-y-4">
                @csrf
                <input type="hidden" name="require_discord_verification" value="0">
                <label class="label cursor-pointer justify-start gap-3">
                    <input class="toggle toggle-primary" type="checkbox" id="requireDiscordVerification" name="require_discord_verification" value="1" @checked($discordVerificationRequired)>
                    <span class="label-text">Require Discord Verification</span>
                </label>
                <div class="pt-2">
                    <button class="btn btn-primary" type="submit">Save Discord Setting</button>
                </div>
            </form>
        </x-card>

        <x-card title="Discord Alliance Departures" subtitle="Send a Discord alert when a non-applicant leaves any alliance in our membership group.">
            <x-slot:menu>
                <span class="badge {{ $discordDepartureEnabled ? 'badge-success' : 'badge-ghost' }}">
                    {{ $discordDepartureEnabled ? 'Enabled' : 'Disabled' }}
                </span>
            </x-slot:menu>

            <form method="POST" action="{{ route('admin.settings.discord.departure') }}" class="space-y-4">
                @csrf
                <label class="block space-y-2">
                    <span class="text-sm font-medium">Channel ID</span>
                    <input type="text" class="input input-bordered" id="discordAllianceDepartureChannelId" name="discord_alliance_departure_channel_id" value="{{ old('discord_alliance_departure_channel_id', $discordDepartureChannelId) }}" placeholder="e.g. 123456789012345678">
                    <span class="text-xs text-base-content/60">Leave blank to reuse the war alert channel.</span>
                </label>
                <input type="hidden" name="discord_alliance_departure_enabled" value="0">
                <label class="label cursor-pointer justify-start gap-3">
                    <input class="toggle toggle-primary" type="checkbox" id="discordAllianceDepartureEnabled" name="discord_alliance_departure_enabled" value="1" @checked($discordDepartureEnabled)>
                    <span class="label-text">Enable departure alerts</span>
                </label>
                <div class="pt-2">
                    <button class="btn btn-primary" type="submit">Save Discord Departure Settings</button>
                </div>
            </form>
        </x-card>

        <x-card title="Auto Withdraw" subtitle="Global toggle for the automatic withdrawal scheduler.">
            <x-slot:menu>
                <span class="badge {{ $autoWithdrawEnabled ? 'badge-success' : 'badge-ghost' }}">
                    {{ $autoWithdrawEnabled ? 'Enabled' : 'Disabled' }}
                </span>
            </x-slot:menu>

            <form method="POST" action="{{ route('admin.settings.auto-withdraw') }}" class="space-y-4">
                @csrf
                <input type="hidden" name="auto_withdraw_enabled" value="0">
                <label class="label cursor-pointer justify-start gap-3">
                    <input class="toggle toggle-primary" type="checkbox" id="autoWithdrawEnabled" name="auto_withdraw_enabled" value="1" @checked($autoWithdrawEnabled)>
                    <span class="label-text">Enable Auto Withdraw</span>
                </label>
                <div class="pt-2">
                    <button class="btn btn-primary" type="submit">Save Auto Withdraw Setting</button>
                </div>
            </form>
        </x-card>

        <x-card title="Backups" subtitle="Run application and database backups every 6 hours.">
            <x-slot:menu>
                <span class="badge {{ $backupsEnabled ? 'badge-success' : 'badge-ghost' }}">
                    {{ $backupsEnabled ? 'Enabled' : 'Disabled' }}
                </span>
            </x-slot:menu>

            <form method="POST" action="{{ route('admin.settings.backups') }}" class="space-y-4">
                @csrf
                <input type="hidden" name="backups_enabled" value="0">
                <label class="label cursor-pointer justify-start gap-3">
                    <input class="toggle toggle-primary" type="checkbox" id="backupsEnabled" name="backups_enabled" value="1" @checked($backupsEnabled)>
                    <span class="label-text">Enable Backups</span>
                </label>
                <div class="pt-2">
                    <button class="btn btn-primary" type="submit">Save Backup Setting</button>
                </div>
            </form>
        </x-card>

        <x-card title="Loan Payments" subtitle="Pause required loan payments during war or special events.">
            <x-slot:menu>
                <span class="badge {{ $loanPaymentsEnabled ? 'badge-success' : 'badge-warning' }}">
                    {{ $loanPaymentsEnabled ? 'Enabled' : 'Paused' }}
                </span>
            </x-slot:menu>

            <div class="space-y-4">
                @if (! $loanPaymentsEnabled && $loanPaymentsPausedAt)
                    <div class="alert alert-warning">
                        <span class="text-sm">Paused since {{ $loanPaymentsPausedAt->format('M d, Y H:i') }}.</span>
                    </div>
                @endif

                <form method="POST" action="{{ route('admin.settings.loan-payments') }}" class="space-y-4">
                    @csrf
                    <input type="hidden" name="loan_payments_enabled" value="0">
                    <label class="label cursor-pointer justify-start gap-3">
                        <input class="toggle toggle-primary" type="checkbox" id="loanPaymentsEnabled" name="loan_payments_enabled" value="1" @checked($loanPaymentsEnabled)>
                        <span class="label-text">Enable Loan Payments</span>
                    </label>
                    <div class="pt-2">
                        <button class="btn btn-primary" type="submit">Save Loan Payment Setting</button>
                    </div>
                </form>
            </div>
        </x-card>

        <x-card title="Account Inactivity Auto-Disable" subtitle="Disable user accounts after a configurable period without activity.">
            <x-slot:menu>
                <span class="badge {{ $userInactivityAutoDisableEnabled ? 'badge-success' : 'badge-ghost' }}">
                    {{ $userInactivityAutoDisableEnabled ? 'Enabled' : 'Disabled' }}
                </span>
            </x-slot:menu>

            <form method="POST" action="{{ route('admin.settings.account-inactivity-auto-disable') }}" class="space-y-4">
                @csrf
                <input type="hidden" name="user_inactivity_auto_disable_enabled" value="0">
                <label class="label cursor-pointer justify-start gap-3">
                    <input
                        class="toggle toggle-primary"
                        type="checkbox"
                        id="userInactivityAutoDisableEnabled"
                        name="user_inactivity_auto_disable_enabled"
                        value="1"
                        @checked(old('user_inactivity_auto_disable_enabled', $userInactivityAutoDisableEnabled))
                    >
                    <span class="label-text">Enable automatic account disabling</span>
                </label>
                <label class="block space-y-2">
                    <span class="text-sm font-medium">Inactivity threshold (days)</span>
                    <input
                        type="number"
                        class="input input-bordered"
                        id="userInactivityAutoDisableDays"
                        name="user_inactivity_auto_disable_days"
                        min="1"
                        max="3650"
                        value="{{ old('user_inactivity_auto_disable_days', $userInactivityAutoDisableDays) }}"
                        required
                    >
                    <span class="text-xs text-base-content/60">Default is 90 days (about 3 months).</span>
                </label>
                <div class="pt-2">
                    <button class="btn btn-primary" type="submit">Save Inactivity Auto-Disable</button>
                </div>
            </form>
        </x-card>

        <x-card title="Grant Approvals" subtitle="Emergency kill switch for grant and city grant approvals.">
            <x-slot:menu>
                <span class="badge {{ $grantApprovalsEnabled ? 'badge-success' : 'badge-warning' }}">
                    {{ $grantApprovalsEnabled ? 'Enabled' : 'Paused' }}
                </span>
            </x-slot:menu>

            <form method="POST" action="{{ route('admin.settings.grants.approvals') }}" class="space-y-4">
                @csrf
                <input type="hidden" name="grant_approvals_enabled" value="0">
                <label class="label cursor-pointer justify-start gap-3">
                    <input class="toggle toggle-primary" type="checkbox" id="grantApprovalsEnabled" name="grant_approvals_enabled" value="1" @checked($grantApprovalsEnabled)>
                    <span class="label-text">Enable Grant Approvals</span>
                </label>
                <div class="pt-2">
                    <button class="btn btn-primary" type="submit">Save Grant Approval Setting</button>
                </div>
            </form>
        </x-card>

        <x-card title="Audit Log Retention" :subtitle="$auditRetentionDays . ' days'">
            <form method="POST" action="{{ route('admin.settings.audit-retention') }}" class="space-y-4">
                @csrf
                <label class="block space-y-2">
                    <span class="text-sm font-medium">Retention (days)</span>
                    <input
                        type="number"
                        class="input input-bordered"
                        id="auditRetentionDays"
                        name="audit_log_retention_days"
                        min="1"
                        max="3650"
                        value="{{ old('audit_log_retention_days', $auditRetentionDays) }}"
                        required
                    >
                    <span class="text-xs text-base-content/60">Use 1–3650 days (up to 10 years).</span>
                </label>
                <div class="pt-2">
                    <button class="btn btn-primary" type="submit">Save Audit Retention</button>
                </div>
            </form>
        </x-card>
    </div>
@endsection
