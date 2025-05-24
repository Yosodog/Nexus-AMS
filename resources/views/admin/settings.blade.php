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
                    Each sync fetches and updates all data for the selected type. Depending on system resources and queue activity, this can take anywhere from a few minutes to nearly an hour.
                </p>
                <p class="mb-0">
                    <strong>Note:</strong> Running syncs consumes queue capacity and may delay other time-sensitive tasks like withdrawals, transfers, and in-game messaging. You can cancel a sync at any time.
                </p>
            </div>
        </div>
    </div>
    <div class="row">
        <div class="col-md-4">
            @include('components.admin.sync-card', [
                'title' => 'Nation Sync',
                'batch' => $nationBatch,
                'route' => route('admin.settings.sync.run'),
            ])
        </div>

        <div class="col-md-4">
            @include('components.admin.sync-card', [
                'title' => 'Alliance Sync',
                'batch' => $allianceBatch,
                'route' => route('admin.settings.sync.alliances'),
            ])
        </div>

        <div class="col-md-4">
            @include('components.admin.sync-card', [
                'title' => 'War Sync',
                'batch' => $warBatch,
                'route' => route('admin.settings.sync.wars'),
            ])
        </div>
    </div>

    {{-- Other Settings Sections Go Below --}}
    <div class="row mt-5">
        <div class="col-md-12">
            <h4 class="mb-3">Other Settings</h4>
            <div class="alert alert-info">
                More configuration options will appear here soon.
            </div>
        </div>
    </div>
@endsection