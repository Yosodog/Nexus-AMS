@extends('layouts.admin')

@section('title', $currentStep->label().' · Alliance setup')

@section('content')
    <header class="nexus-page-header">
        <div class="nexus-page-header__copy">
            <p class="text-sm font-semibold uppercase tracking-wide text-primary">Alliance setup</p>
            <h1 class="nexus-page-title">{{ $currentStep->label() }}</h1>
            <p class="nexus-page-summary">This guide is resumable and never locks you out of normal administration.</p>
        </div>
        <a href="{{ route('admin.setup.index') }}" class="btn btn-ghost btn-sm">Setup overview</a>
    </header>

    @include('admin.setup.partials.navigation')

    @if ($errors->any())
        <div class="alert alert-error" role="alert" tabindex="-1">
            <x-icon name="o-exclamation-circle" class="size-5" aria-hidden="true" />
            <div>
                <p class="font-semibold">Check the highlighted setup details.</p>
                <ul class="mt-1 list-inside list-disc text-sm">
                    @foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach
                </ul>
            </div>
        </div>
    @endif

    @if ($setupState->corrupt)
        <div class="alert alert-error" role="alert">
            <span>Setup metadata is invalid. Return to the overview to reset only this metadata.</span>
            <a href="{{ route('admin.setup.index') }}" class="btn btn-sm">Open recovery</a>
        </div>
    @elseif ($currentStep === \App\Enums\AllianceSetupStep::Platform)
        <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_22rem]">
            <section class="nexus-panel overflow-hidden" aria-labelledby="platform-checks-title">
                <div class="border-b border-base-300 p-5"><h2 id="platform-checks-title" class="text-lg font-semibold">Required checks</h2></div>
                <ul class="divide-y divide-base-300">
                    @foreach ($snapshot['required'] as $check)
                        <li class="flex items-start gap-3 p-4">
                            <x-icon :name="$check['passed'] ? 'o-check-circle' : 'o-x-circle'" class="mt-0.5 size-5 {{ $check['passed'] ? 'text-success' : 'text-error' }}" aria-hidden="true" />
                            <span><strong class="block">{{ $check['label'] }}</strong><span class="text-sm text-base-content/65">{{ $check['detail'] }}</span></span>
                        </li>
                    @endforeach
                </ul>
            </section>
            <aside class="nexus-panel p-5">
                <h2 class="font-semibold">Runtime ownership</h2>
                <dl class="mt-3 grid grid-cols-[auto_1fr] gap-x-3 gap-y-2 text-sm">
                    <dt class="text-base-content/60">Mode</dt><dd class="font-medium">{{ $snapshot['context']['runtime'] }}</dd>
                    <dt class="text-base-content/60">Ownership</dt><dd class="font-medium">{{ $snapshot['context']['managed'] ? 'Nexus Cloud managed' : 'Standalone' }}</dd>
                    <dt class="text-base-content/60">Alliance</dt><dd class="font-medium">{{ $snapshot['context']['alliance_name'] ?: 'Not loaded' }}</dd>
                </dl>
                @if ($snapshot['context']['managed'])
                    <p class="mt-4 text-sm text-base-content/70">Deployment credentials are managed by Nexus Cloud. This page only reports whether AMS received them.</p>
                @else
                    <p class="mt-4 text-sm text-base-content/70">Configure <code>PW_ALLIANCE_ID</code>, <code>PW_API_KEY</code>, and <code>PW_API_MUTATION_KEY</code> in the standalone environment.</p>
                @endif
                <a href="{{ route('admin.settings.data-sync') }}" class="btn btn-outline btn-sm mt-4">Open data-sync diagnostics</a>
            </aside>
        </div>
        <form method="POST" action="{{ route('admin.setup.advance', $currentStep) }}" class="flex justify-end">@csrf<button class="btn btn-primary" type="submit">Continue to security</button></form>
    @elseif ($currentStep === \App\Enums\AllianceSetupStep::Security)
        <section class="nexus-panel p-5 sm:p-6">
            <h2 class="text-lg font-semibold">Secure this local administrator</h2>
            <p class="mt-2 text-base-content/70">Nexus Cloud identity and tenant-local Nexus AMS identity are separate. Configure sign-in protection in this installation.</p>
            <div class="mt-5 grid gap-4 sm:grid-cols-2">
                <div class="rounded-box border border-base-300 p-4"><x-nexus-status :label="$snapshot['context']['has_totp'] ? 'TOTP configured' : 'TOTP recommended'" :intent="$snapshot['context']['has_totp'] ? 'success' : 'warning'" :icon="$snapshot['context']['has_totp'] ? 'check-circle' : 'exclamation-triangle'" /></div>
                <div class="rounded-box border border-base-300 p-4"><x-nexus-status :label="$snapshot['context']['has_passkey'] ? 'Passkey registered' : 'Passkey recommended'" :intent="$snapshot['context']['has_passkey'] ? 'success' : 'warning'" :icon="$snapshot['context']['has_passkey'] ? 'check-circle' : 'exclamation-triangle'" /></div>
            </div>
            <div class="mt-5 flex flex-wrap gap-3">
                <a href="{{ route('user.settings') }}" class="btn btn-outline">Open local security settings</a>
                @if (auth()->user()->can('edit-users') && auth()->user()->can('bypass-self-restrictions'))
                    <a href="{{ route('admin.users.index') }}#mfa-requirements" class="btn btn-ghost">Administrator MFA policy</a>
                @endif
            </div>
        </section>
        <form method="POST" action="{{ route('admin.setup.advance', $currentStep) }}" class="flex justify-end">@csrf<button class="btn btn-primary" type="submit">Continue to Discord</button></form>
    @elseif ($currentStep === \App\Enums\AllianceSetupStep::Discord)
        <section class="nexus-panel p-5 sm:p-6">
            <h2 class="text-lg font-semibold">Local connection status</h2>
            <p class="mt-2 text-base-content/70">No Discord request is made from this page.</p>
            <div class="mt-4"><x-nexus-status :label="$snapshot['context']['discord']['connected'] ? 'Discord connected' : 'Discord not connected'" :intent="$snapshot['context']['discord']['connected'] ? 'success' : 'warning'" :icon="$snapshot['context']['discord']['connected'] ? 'check-circle' : 'exclamation-triangle'" /></div>
            @if ($snapshot['context']['discord']['connected'])
                <p class="mt-2 text-sm text-base-content/65">{{ ucfirst($snapshot['context']['discord']['source']) }} · {{ $snapshot['context']['discord']['mode'] }}</p>
            @elseif ($snapshot['context']['managed'])
                <p class="mt-3 text-sm text-base-content/70">Ask a Nexus Cloud administrator to finish the managed Discord connection. AMS never accepts or displays a bot token here.</p>
            @else
                <a href="{{ route('user.discord-bot-guide') }}" class="btn btn-outline btn-sm mt-4">Open standalone Discord guide</a>
            @endif

            <form method="POST" action="{{ route('admin.setup.discord.update') }}" class="mt-6 space-y-4">
                @csrf
                <label class="flex cursor-pointer items-start gap-3"><input type="radio" name="configure_now" value="1" class="radio radio-primary mt-1" @checked(old('configure_now', $snapshot['context']['discord']['connected'] ? '1' : '0') === '1')><span><strong class="block">Use Discord now</strong><span class="text-sm text-base-content/65">Apply the preferences below. Requires a local accepted connection.</span></span></label>
                <label class="flex cursor-pointer items-start gap-3"><input type="radio" name="configure_now" value="0" class="radio radio-primary mt-1" @checked(old('configure_now', $snapshot['context']['discord']['connected'] ? '1' : '0') === '0')><span><strong class="block">Configure later</strong><span class="text-sm text-base-content/65">Keep verification enforcement and private notifications disabled.</span></span></label>
                <div class="grid gap-3 rounded-box bg-base-200 p-4 sm:grid-cols-2">
                    <label class="label cursor-pointer justify-start gap-3"><input type="checkbox" name="verification_required" value="1" class="checkbox checkbox-primary" @checked(old('verification_required', $snapshot['context']['discord_verification_required']))><span>Require Discord verification</span></label>
                    <label class="label cursor-pointer justify-start gap-3"><input type="checkbox" name="private_notifications_enabled" value="1" class="checkbox checkbox-primary" @checked(old('private_notifications_enabled', $snapshot['context']['discord_private_notifications']))><span>Allow private notifications</span></label>
                </div>
                <div class="flex justify-end"><button class="btn btn-primary" type="submit">Save and continue</button></div>
            </form>
        </section>
    @elseif ($currentStep === \App\Enums\AllianceSetupStep::Recruitment)
        <section class="nexus-panel p-5 sm:p-6">
            <h2 class="text-lg font-semibold">Application intake</h2>
            <p class="mt-2 text-base-content/70">This guide changes only core intake settings. Existing Discord role and channel mappings are preserved.</p>
            @can('manage-applications')
                <form method="POST" action="{{ route('admin.setup.recruitment.update') }}" class="mt-6 space-y-5">
                    @csrf
                    <label class="label cursor-pointer justify-start gap-3"><input type="checkbox" name="applications_enabled" value="1" class="checkbox checkbox-primary" @checked(old('applications_enabled', $snapshot['context']['applications_enabled']))><span class="font-medium">Enable applications</span></label>
                    <label class="form-control max-w-md"><span class="label-text font-medium">Approved alliance-position ID</span><input type="number" min="1" name="approved_position_id" value="{{ old('approved_position_id', $snapshot['context']['approved_position_id']) }}" class="input input-bordered mt-2"></label>
                    <label class="form-control"><span class="label-text font-medium">Approval message</span><textarea name="approval_message" class="textarea textarea-bordered mt-2" rows="4">{{ old('approval_message', $snapshot['context']['approval_message']) }}</textarea></label>
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <a href="{{ route('admin.applications.index') }}#application-settings" class="btn btn-ghost">Advanced application settings</a>
                        <button class="btn btn-primary" type="submit">Save and continue</button>
                    </div>
                </form>
            @else
                <div class="alert alert-info mt-5"><span>You need the manage-applications permission to change these settings. You may continue without changing them.</span></div>
                <form method="POST" action="{{ route('admin.setup.advance', $currentStep) }}" class="mt-5 flex justify-end">@csrf<button class="btn btn-primary" type="submit">Continue to review</button></form>
            @endcan
        </section>
    @else
        <div class="grid gap-6 lg:grid-cols-2">
            <section class="nexus-panel overflow-hidden" aria-labelledby="review-required-title">
                <div class="border-b border-base-300 p-5"><h2 id="review-required-title" class="text-lg font-semibold">Required blockers</h2></div>
                <ul class="divide-y divide-base-300">
                    @forelse (collect($snapshot['required'])->where('passed', false) as $check)
                        <li class="p-4"><strong class="block text-error">{{ $check['label'] }}</strong><span class="text-sm text-base-content/65">{{ $check['detail'] }}</span></li>
                    @empty
                        <li class="p-5 text-success">All required platform and data checks pass.</li>
                    @endforelse
                </ul>
            </section>
            <section class="nexus-panel overflow-hidden" aria-labelledby="review-warnings-title">
                <div class="border-b border-base-300 p-5"><h2 id="review-warnings-title" class="text-lg font-semibold">Advisory warnings</h2></div>
                <ul class="divide-y divide-base-300">
                    @forelse ($snapshot['warnings'] as $warning)
                        <li class="p-4"><strong class="block text-warning">{{ $warning['label'] }}</strong><span class="text-sm text-base-content/65">{{ $warning['detail'] }}</span></li>
                    @empty
                        <li class="p-5 text-success">No advisory warnings remain.</li>
                    @endforelse
                </ul>
            </section>
        </div>
        <section class="nexus-panel p-5 sm:p-6">
            <p class="text-base-content/70">Security, Discord, and recruitment recommendations do not block completion. The required readiness checks are evaluated again when you finish.</p>
            <form method="POST" action="{{ route('admin.setup.complete') }}" class="mt-4 flex justify-end">
                @csrf
                <button class="btn btn-primary" type="submit" @disabled(! $snapshot['ready'])>Finish setup</button>
            </form>
        </section>
    @endif
@endsection
