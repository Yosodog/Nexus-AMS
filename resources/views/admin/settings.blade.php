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
    <div class="row">
        <div class="col-md-12">
            <h4 class="mb-3">Data Synchronization</h4>
        </div>

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