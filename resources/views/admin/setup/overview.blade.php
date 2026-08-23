@extends('layouts.admin')

@section('title', 'Setup & readiness')

@section('content')
    <header class="nexus-page-header">
        <div class="nexus-page-header__copy">
            <h1 class="nexus-page-title">Setup &amp; readiness</h1>
            <p class="nexus-page-summary">Review the installation boundary and guide administrators through core alliance configuration.</p>
        </div>
    </header>

    @if ($setupState->corrupt)
        <section class="alert alert-error" role="alert" aria-labelledby="setup-recovery-title">
            <x-icon name="o-exclamation-triangle" class="size-6" aria-hidden="true" />
            <div>
                <h2 id="setup-recovery-title" class="font-semibold">Setup metadata needs recovery</h2>
                <p>The saved setup version is malformed or unsupported. Other alliance settings have not been changed.</p>
                <form method="POST" action="{{ route('admin.setup.reset') }}" class="mt-3">
                    @csrf
                    <button class="btn btn-error btn-sm" type="submit">Reset setup metadata</button>
                </form>
            </div>
        </section>
    @elseif ($setupState->legacy)
        <section class="nexus-panel p-5 sm:p-6">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <x-nexus-status label="Legacy installation · complete" intent="success" icon="check-circle" />
                    <h2 class="mt-3 text-xl font-semibold">This installation was grandfathered</h2>
                    <p class="mt-2 max-w-3xl text-base-content/70">No setup metadata existed when this feature was introduced, so normal administration remains undisturbed. You can voluntarily run the guide at any time.</p>
                </div>
                <form method="POST" action="{{ route('admin.setup.start') }}">
                    @csrf
                    <button class="btn btn-primary" type="submit">Run guided setup</button>
                </form>
            </div>
        </section>
    @else
        <section class="nexus-panel p-5 sm:p-6">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <x-nexus-status
                        :label="$setupState->isIncomplete() ? 'Setup incomplete' : 'Setup complete'"
                        :intent="$setupState->isIncomplete() ? 'warning' : 'success'"
                        :icon="$setupState->isIncomplete() ? 'exclamation-triangle' : 'check-circle'"
                    />
                    <h2 class="mt-3 text-xl font-semibold">Alliance setup version {{ $setupState->version }}</h2>
                    <p class="mt-2 text-base-content/70">
                        {{ $setupState->isIncomplete() ? 'Resume where the last administrator stopped. Normal administration remains available.' : 'Core platform readiness was confirmed. You can review or rerun the guide whenever needed.' }}
                    </p>
                </div>
                @if ($setupState->isIncomplete())
                    <a href="{{ route($setupState->currentStep->routeName()) }}" class="btn btn-primary">Resume setup</a>
                @else
                    <form method="POST" action="{{ route('admin.setup.start') }}">
                        @csrf
                        <button class="btn btn-outline" type="submit">Run again</button>
                    </form>
                @endif
            </div>
        </section>

        @if ($snapshot)
            <div class="grid gap-4 md:grid-cols-2">
                <section class="nexus-panel p-5" aria-labelledby="required-summary-title">
                    <h2 id="required-summary-title" class="text-lg font-semibold">Required readiness</h2>
                    <p class="mt-2 text-3xl font-bold">{{ collect($snapshot['required'])->where('passed', true)->count() }}/{{ count($snapshot['required']) }}</p>
                    <p class="text-sm text-base-content/65">platform and data checks passing</p>
                </section>
                <section class="nexus-panel p-5" aria-labelledby="warning-summary-title">
                    <h2 id="warning-summary-title" class="text-lg font-semibold">Advisory findings</h2>
                    <p class="mt-2 text-3xl font-bold">{{ count($snapshot['warnings']) }}</p>
                    <p class="text-sm text-base-content/65">recommendations that do not block completion</p>
                </section>
            </div>
        @endif
    @endif
@endsection
