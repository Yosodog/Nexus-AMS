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
                    Nexus AMS typically keeps nation, alliance, and war data updated in near real-time using live subscriptions to the Politics & War API.
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
    </div>
@endsection
