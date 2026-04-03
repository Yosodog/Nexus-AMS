@extends('layouts.admin')

@section("content")
    <div class="app-content-header">
        <div class="container-fluid">
            <div class="row mb-3">
                <div class="col-sm-6">
                    <h3 class="mb-0">Admin Settings</h3>
                </div>
            </div>
        </div>
    </div>

    {{-- Sync Settings Section --}}
    <div class="row mb-3">
        <div class="col-md-12 d-flex justify-content-between align-items-center">
            <h4 class="mb-0">Data Synchronization</h4>
            <a href="#" data-bs-toggle="collapse" data-bs-target="#syncHelp" class="text-muted small">
                <i class="bi bi-question-circle me-1"></i> Learn more about data sync
            </a>
        </div>
    </div>

    <div class="row collapse mb-4" id="syncHelp">
        <div class="col-md-12">
            <div class="alert alert-light border">
                <p class="mb-2">
                    {{ config('app.name') }} typically keeps nation, alliance, and war data updated in near real-time using live subscriptions to the Politics & War API.
                    However, these subscriptions are not guaranteed and may occasionally miss updates due to network or service disruptions.
                </p>
                <p class="mb-2">
                    Full sync jobs are automatically scheduled and run periodically, so manual execution is rarely needed. Manual sync should only be used to correct known discrepancies.
                </p>
                <p class="mb-2">
                    The <strong>Manual Nation Sync</strong> runs immediately and cancels any in-progress rolling nation sync. The <strong>Rolling Nation Sync</strong> is queued by the scheduler and staggers the workload across ~23 hours; use the status card below to see when the last rolling job ran and when the next one is scheduled. Nations below 500 score are only included on the sync on Mondays.
                </p>
                <p class="mb-2">
                    Each sync fetches and updates all data for the selected type. Depending on system resources and queue activity, this can take anywhere from a few minutes to nearly an hour.
                </p>
                <p class="mb-0">
                    <strong>Note:</strong> Running syncs consumes queue capacity and may delay other time-sensitive tasks like withdrawals, transfers, and in-game messaging. You can cancel a sync at any time.
                </p>
            </div>
        </div>
    </div>
    <div class="row">
        <div class="col-md-6">
            @include('components.admin.sync-card', [
                'title' => 'Nation Sync (Manual)',
                'batch' => $nationBatch,
                'route' => route('admin.settings.sync.run'),
            ])
        </div>

        <div class="col-md-6">
            @include('components.admin.rolling-sync-card', [
                'batch' => $rollingNationBatch,
                'rollingSchedule' => $rollingSchedule,
            ])
        </div>
    </div>

    <div class="row mt-4">
        <div class="col-md-6">
            @include('components.admin.sync-card', [
                'title' => 'Alliance Sync',
                'batch' => $allianceBatch,
                'route' => route('admin.settings.sync.alliances'),
            ])
        </div>

        <div class="col-md-6">
            @include('components.admin.sync-card', [
                'title' => 'War Sync',
                'batch' => $warBatch,
                'route' => route('admin.settings.sync.wars'),
            ])
        </div>
    </div>

    {{-- Other Settings Sections Go Below --}}
    <div class="row mt-2 g-3">
        <div class="col-md-12">
            <h4 class="mb-2">Other Settings</h4>
        </div>
        <div class="col-12">
            <div class="card shadow-sm border-warning">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span>Pending Request Recovery</span>
                    <span class="badge text-bg-warning">Diagnostics</span>
                </div>
                <div class="card-body">
                    <p class="text-muted mb-3">
                        Use this only when a workflow is genuinely stuck. Releasing stale pending rows clears the single-pending lock so members can submit a fresh request.
                    </p>
                    <div class="alert alert-warning py-2 px-3 small">
                        These actions do not approve anything. They force stale pending rows into a terminal state based on the workflow, such as denied, cancelled, or expired.
                    </div>
                    <div class="table-responsive">
                        <table class="table align-middle mb-0">
                            <thead>
                            <tr>
                                <th scope="col">Workflow</th>
                                <th scope="col">Pending</th>
                                <th scope="col">Stale ({{ $stalePendingDefaultHours }}h+)</th>
                                <th scope="col">Oldest Pending</th>
                                <th scope="col" style="width: 340px;">Force Release</th>
                            </tr>
                            </thead>
                            <tbody>
                            @foreach($pendingRecoveryItems as $item)
                                <tr>
                                    <td class="text-capitalize">{{ $item['label'] }}</td>
                                    <td>{{ number_format($item['totalPending']) }}</td>
                                    <td>
                                        <span class="badge {{ $item['stalePending'] > 0 ? 'text-bg-warning' : 'text-bg-secondary' }}">
                                            {{ number_format($item['stalePending']) }}
                                        </span>
                                    </td>
                                    <td>
                                        @if($item['oldestCreatedAt'])
                                            <div>{{ $item['oldestCreatedAt']->format('M d, Y H:i') }}</div>
                                            <small class="text-muted">{{ $item['oldestCreatedAt']->diffForHumans() }}</small>
                                        @else
                                            <span class="text-muted">None</span>
                                        @endif
                                    </td>
                                    <td>
                                        <form method="POST" action="{{ route('admin.settings.pending-requests.release-stale') }}" class="d-flex gap-2 align-items-end">
                                            @csrf
                                            <input type="hidden" name="type" value="{{ $item['type'] }}">
                                            <div>
                                                <label class="form-label small mb-1" for="olderThanHours-{{ $item['type'] }}">Older than (hours)</label>
                                                <input type="number"
                                                       class="form-control form-control-sm"
                                                       id="olderThanHours-{{ $item['type'] }}"
                                                       name="older_than_hours"
                                                       min="1"
                                                       max="8760"
                                                       value="{{ old('older_than_hours', $stalePendingDefaultHours) }}"
                                                       required>
                                            </div>
                                            <button class="btn btn-outline-warning btn-sm mb-0" type="submit">
                                                Release Stale
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            @php
                $highlightInputs = old('home_highlights', $homepageSettings['highlights'] ?? []);
                $highlightInputs = array_pad($highlightInputs, 3, '');
            @endphp
            <div class="card shadow-sm h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span>Homepage Messaging</span>
                    <span class="badge text-bg-info">Public</span>
                </div>
                <div class="card-body">
                    <p class="text-muted">
                        Customize the guest-facing homepage for your alliance. Content stays alliance-agnostic by default and can be tailored as your branding evolves.
                    </p>
                    <form method="POST" action="{{ route('admin.settings.homepage') }}">
                        @csrf
                        <div class="mb-3">
                            <label class="form-label" for="homeHeadline">Headline</label>
                            <input type="text" class="form-control" id="homeHeadline" name="home_headline"
                                   value="{{ old('home_headline', $homepageSettings['headline'] ?? '') }}" maxlength="160" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="homeTagline">Tagline</label>
                            <input type="text" class="form-control" id="homeTagline" name="home_tagline"
                                   value="{{ old('home_tagline', $homepageSettings['tagline'] ?? '') }}" maxlength="240" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="homeAbout">About blurb</label>
                            <textarea class="form-control" id="homeAbout" name="home_about" rows="3" maxlength="800" placeholder="Short paragraph for guests">{{ old('home_about', $homepageSettings['about'] ?? '') }}</textarea>
                            <small class="text-muted">Shown beneath the hero to explain what makes your alliance stand out.</small>
                        </div>
                        <div class="mb-2">
                            <label class="form-label">Highlights (optional)</label>
                            @foreach($highlightInputs as $index => $highlight)
                                <input type="text"
                                       class="form-control mb-2"
                                       name="home_highlights[]"
                                       value="{{ $highlight }}"
                                       maxlength="140"
                                       placeholder="e.g. Fast onboarding with clear expectations">
                            @endforeach
                            <small class="text-muted">These become quick bullet points on the homepage. Leave blank to use defaults.</small>
                        </div>
                        <button class="btn btn-primary">Save Homepage Content</button>
                    </form>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            @php
                $canUploadFavicon = auth()->user()?->can('view-diagnostic-info') ?? false;
            @endphp
            <div class="card shadow-sm {{ $canUploadFavicon ? '' : 'opacity-50' }}">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span>Favicon</span>
                    <span class="badge text-bg-secondary">Branding</span>
                </div>
                <div class="card-body">
                    <p class="text-muted">
                        Upload a square icon (PNG, ICO, SVG, or JPG) to update the browser favicon across the site.
                    </p>
                    <div class="d-flex align-items-center gap-3 mb-3">
                        <div class="border rounded bg-white d-flex align-items-center justify-content-center" style="width: 52px; height: 52px;">
                            <img src="{{ $faviconUrl }}" alt="Current favicon" class="img-fluid" style="max-width: 32px; max-height: 32px;">
                        </div>
                        <div class="small text-muted">
                            Current favicon preview
                        </div>
                    </div>
                    <form method="POST" action="{{ route('admin.settings.favicon') }}" enctype="multipart/form-data">
                        @csrf
                        <div class="mb-3">
                            <label class="form-label" for="faviconUpload">Favicon file</label>
                            <input type="file"
                                   class="form-control"
                                   id="faviconUpload"
                                   name="favicon"
                                   accept=".png,.ico,.svg,.jpg,.jpeg"
                                   @disabled(! $canUploadFavicon)
                                   required>
                            <small class="text-muted">Recommended: 32x32 or 64x64.</small>
                        </div>
                        <button class="btn btn-primary" @disabled(! $canUploadFavicon)>Upload Favicon</button>
                        @if (! $canUploadFavicon)
                            <div class="form-text text-muted mt-2">
                                Requires the View Diagnostic permission.
                            </div>
                        @endif
                    </form>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card shadow-sm">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span>Discord Verification</span>
                    <span class="badge {{ $discordVerificationRequired ? 'text-bg-success' : 'text-bg-secondary' }}">
                        {{ $discordVerificationRequired ? 'Required' : 'Optional' }}
                    </span>
                </div>
                <div class="card-body">
                    <p class="text-muted">
                        Control whether members must complete Discord verification after in-game verification. When enabled,
                        users without an active Discord link are redirected to the verification page.
                    </p>
                    <form method="POST" action="{{ route('admin.settings.discord') }}">
                        @csrf
                        <div class="form-check form-switch mb-3">
                            <input type="hidden" name="require_discord_verification" value="0">
                            <input class="form-check-input" type="checkbox" role="switch" id="requireDiscordVerification"
                                   name="require_discord_verification" value="1" @checked($discordVerificationRequired)>
                            <label class="form-check-label" for="requireDiscordVerification">Require Discord Verification</label>
                        </div>
                        <button class="btn btn-primary">Save Discord Setting</button>
                    </form>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card shadow-sm">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span>Discord Alliance Departures</span>
                    <span class="badge {{ $discordDepartureEnabled ? 'text-bg-success' : 'text-bg-secondary' }}">
                        {{ $discordDepartureEnabled ? 'Enabled' : 'Disabled' }}
                    </span>
                </div>
                <div class="card-body">
                    <p class="text-muted">
                        Send a Discord alert when a non-applicant leaves any alliance in our membership group.
                        Defaults to the war alert channel if left blank.
                    </p>
                    <form method="POST" action="{{ route('admin.settings.discord.departure') }}">
                        @csrf
                        <div class="mb-3">
                            <label for="discordAllianceDepartureChannelId" class="form-label">Channel ID</label>
                            <input type="text"
                                   class="form-control"
                                   id="discordAllianceDepartureChannelId"
                                   name="discord_alliance_departure_channel_id"
                                   value="{{ old('discord_alliance_departure_channel_id', $discordDepartureChannelId) }}"
                                   placeholder="e.g. 123456789012345678">
                            <small class="text-muted">Leave blank to reuse the war alert channel.</small>
                        </div>
                        <div class="form-check form-switch mb-3">
                            <input type="hidden" name="discord_alliance_departure_enabled" value="0">
                            <input class="form-check-input" type="checkbox" role="switch" id="discordAllianceDepartureEnabled"
                                   name="discord_alliance_departure_enabled" value="1" @checked($discordDepartureEnabled)>
                            <label class="form-check-label" for="discordAllianceDepartureEnabled">Enable departure alerts</label>
                        </div>
                        <button class="btn btn-primary">Save Discord Departure Settings</button>
                    </form>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card shadow-sm">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span>Auto Withdraw</span>
                    <span class="badge {{ $autoWithdrawEnabled ? 'text-bg-success' : 'text-bg-secondary' }}">
                        {{ $autoWithdrawEnabled ? 'Enabled' : 'Disabled' }}
                    </span>
                </div>
                <div class="card-body">
                    <p class="text-muted">
                        Global toggle for the automatic withdrawal scheduler. Disabling keeps member settings intact but stops scheduled runs.
                    </p>
                    <form method="POST" action="{{ route('admin.settings.auto-withdraw') }}">
                        @csrf
                        <div class="form-check form-switch mb-3">
                            <input type="hidden" name="auto_withdraw_enabled" value="0">
                            <input class="form-check-input" type="checkbox" role="switch" id="autoWithdrawEnabled"
                                   name="auto_withdraw_enabled" value="1" @checked($autoWithdrawEnabled)>
                            <label class="form-check-label" for="autoWithdrawEnabled">Enable Auto Withdraw</label>
                        </div>
                        <button class="btn btn-primary">Save Auto Withdraw Setting</button>
                    </form>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card shadow-sm">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span>Growth Circles</span>
                    <span class="badge {{ $growthCirclesEnabled ? 'text-bg-success' : 'text-bg-secondary' }}">
                        {{ $growthCirclesEnabled ? 'Enabled' : 'Disabled' }}
                    </span>
                </div>
                <div class="card-body">
                    <p class="text-muted">
                        Opt-in program that taxes members at 100% and automatically distributes food and uranium to support their growth.
                    </p>
                    <form method="POST" action="{{ route('admin.settings.growth-circles') }}">
                        @csrf
                        <div class="form-check form-switch mb-3">
                            <input type="hidden" name="growth_circles_enabled" value="0">
                            <input class="form-check-input" type="checkbox" role="switch" id="growthCirclesEnabled"
                                   name="growth_circles_enabled" value="1" @checked($growthCirclesEnabled)>
                            <label class="form-check-label" for="growthCirclesEnabled">Enable Growth Circles</label>
                        </div>
                        <div class="mb-3">
                            <label for="gcTaxId" class="form-label">P&W Tax Bracket ID (100%)</label>
                            <input type="number" class="form-control" id="gcTaxId"
                                   name="growth_circle_tax_id" value="{{ $growthCircleTaxId }}" min="0">
                        </div>
                        <div class="mb-3">
                            <label for="gcFallbackTaxId" class="form-label">Fallback Tax Bracket ID</label>
                            <input type="number" class="form-control" id="gcFallbackTaxId"
                                   name="growth_circle_fallback_tax_id" value="{{ $growthCircleFallbackTaxId }}" min="0">
                        </div>
                        <div class="mb-3">
                            <label for="gcSourceAccount" class="form-label">Source Account</label>
                            <select class="form-select" id="gcSourceAccount" name="growth_circle_source_account_id">
                                <option value="0">— Select account —</option>
                                @foreach ($sourceAccounts as $account)
                                    <option value="{{ $account->id }}" @selected($growthCircleSourceAccountId === $account->id)>
                                        {{ $account->name }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="row g-3 mb-3">
                            <div class="col">
                                <label for="gcFoodPerCity" class="form-label">Food per city</label>
                                <input type="number" class="form-control" id="gcFoodPerCity"
                                       name="growth_circle_food_per_city" value="{{ $growthCircleFoodPerCity }}" min="0">
                            </div>
                            <div class="col">
                                <label for="gcUraniumPerCity" class="form-label">Uranium per city</label>
                                <input type="number" class="form-control" id="gcUraniumPerCity"
                                       name="growth_circle_uranium_per_city" value="{{ $growthCircleUraniumPerCity }}" min="0">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="gcDiscordChannel" class="form-label">Abuse Alert Discord Channel ID</label>
                            <input type="text" class="form-control" id="gcDiscordChannel"
                                   name="growth_circle_discord_channel_id" value="{{ $growthCircleDiscordChannelId }}">
                        </div>
                        <button class="btn btn-primary">Save Growth Circles Settings</button>
                    </form>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card shadow-sm">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span>Backups</span>
                    <span class="badge {{ $backupsEnabled ? 'text-bg-success' : 'text-bg-secondary' }}">
                        {{ $backupsEnabled ? 'Enabled' : 'Disabled' }}
                    </span>
                </div>
                <div class="card-body">
                    <p class="text-muted">
                        Run application + database backups every 6 hours. Backups are stored in AWS S3 only.
                    </p>
                    <form method="POST" action="{{ route('admin.settings.backups') }}">
                        @csrf
                        <div class="form-check form-switch mb-3">
                            <input type="hidden" name="backups_enabled" value="0">
                            <input class="form-check-input" type="checkbox" role="switch" id="backupsEnabled"
                                   name="backups_enabled" value="1" @checked($backupsEnabled)>
                            <label class="form-check-label" for="backupsEnabled">Enable Backups</label>
                        </div>
                        <button class="btn btn-primary">Save Backup Setting</button>
                    </form>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card shadow-sm">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span>Loan Payments</span>
                    <span class="badge {{ $loanPaymentsEnabled ? 'text-bg-success' : 'text-bg-warning' }}">
                        {{ $loanPaymentsEnabled ? 'Enabled' : 'Paused' }}
                    </span>
                </div>
                <div class="card-body">
                    <p class="text-muted">
                        Pause required loan payments during war or special events. When paused, scheduled deductions and
                        interest are frozen, and due dates shift forward when payments resume.
                    </p>
                    @if (! $loanPaymentsEnabled && $loanPaymentsPausedAt)
                        <div class="alert alert-warning py-2 px-3 small mb-3">
                            Paused since {{ $loanPaymentsPausedAt->format('M d, Y H:i') }}.
                        </div>
                    @endif
                    <form method="POST" action="{{ route('admin.settings.loan-payments') }}">
                        @csrf
                        <div class="form-check form-switch mb-3">
                            <input type="hidden" name="loan_payments_enabled" value="0">
                            <input class="form-check-input" type="checkbox" role="switch" id="loanPaymentsEnabled"
                                   name="loan_payments_enabled" value="1" @checked($loanPaymentsEnabled)>
                            <label class="form-check-label" for="loanPaymentsEnabled">Enable Loan Payments</label>
                        </div>
                        <button class="btn btn-primary">Save Loan Payment Setting</button>
                    </form>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card shadow-sm">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span>Account Inactivity Auto-Disable</span>
                    <span class="badge {{ $userInactivityAutoDisableEnabled ? 'text-bg-success' : 'text-bg-secondary' }}">
                        {{ $userInactivityAutoDisableEnabled ? 'Enabled' : 'Disabled' }}
                    </span>
                </div>
                <div class="card-body">
                    <p class="text-muted">
                        Automatically disable user accounts when there has been no activity for the configured number of days.
                        Activity is based on each user's <code>last_active_at</code> timestamp.
                    </p>
                    <form method="POST" action="{{ route('admin.settings.account-inactivity-auto-disable') }}">
                        @csrf
                        <div class="form-check form-switch mb-3">
                            <input type="hidden" name="user_inactivity_auto_disable_enabled" value="0">
                            <input class="form-check-input" type="checkbox" role="switch" id="userInactivityAutoDisableEnabled"
                                   name="user_inactivity_auto_disable_enabled" value="1"
                                   @checked(old('user_inactivity_auto_disable_enabled', $userInactivityAutoDisableEnabled))>
                            <label class="form-check-label" for="userInactivityAutoDisableEnabled">Enable automatic account disabling</label>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="userInactivityAutoDisableDays">Inactivity threshold (days)</label>
                            <input type="number"
                                   class="form-control"
                                   id="userInactivityAutoDisableDays"
                                   name="user_inactivity_auto_disable_days"
                                   min="1"
                                   max="3650"
                                   value="{{ old('user_inactivity_auto_disable_days', $userInactivityAutoDisableDays) }}"
                                   required>
                            <small class="text-muted">Default is 90 days (about 3 months).</small>
                        </div>
                        <button class="btn btn-primary">Save Inactivity Auto-Disable</button>
                    </form>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card shadow-sm">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span>Grant Approvals</span>
                    <span class="badge {{ $grantApprovalsEnabled ? 'text-bg-success' : 'text-bg-warning' }}">
                        {{ $grantApprovalsEnabled ? 'Enabled' : 'Paused' }}
                    </span>
                </div>
                <div class="card-body">
                    <p class="text-muted">
                        Emergency kill switch for grant and city grant approvals. Requests can still be submitted,
                        but approvals are blocked until re-enabled.
                    </p>
                    <form method="POST" action="{{ route('admin.settings.grants.approvals') }}">
                        @csrf
                        <div class="form-check form-switch mb-3">
                            <input type="hidden" name="grant_approvals_enabled" value="0">
                            <input class="form-check-input" type="checkbox" role="switch" id="grantApprovalsEnabled"
                                   name="grant_approvals_enabled" value="1" @checked($grantApprovalsEnabled)>
                            <label class="form-check-label" for="grantApprovalsEnabled">Enable Grant Approvals</label>
                        </div>
                        <button class="btn btn-primary">Save Grant Approval Setting</button>
                    </form>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card shadow-sm">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span>Audit Log Retention</span>
                    <span class="badge text-bg-secondary">{{ $auditRetentionDays }} days</span>
                </div>
                <div class="card-body">
                    <p class="text-muted">
                        Control how long audit log entries are retained before automatic pruning.
                    </p>
                    <form method="POST" action="{{ route('admin.settings.audit-retention') }}">
                        @csrf
                        <div class="mb-3">
                            <label class="form-label" for="auditRetentionDays">Retention (days)</label>
                            <input type="number"
                                   class="form-control"
                                   id="auditRetentionDays"
                                   name="audit_log_retention_days"
                                   min="1"
                                   max="3650"
                                   value="{{ old('audit_log_retention_days', $auditRetentionDays) }}"
                                   required>
                            <small class="text-muted">Use 1–3650 days (up to 10 years).</small>
                        </div>
                        <button class="btn btn-primary">Save Audit Retention</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
