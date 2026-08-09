@extends('layouts.public')

@section('title', 'Sign in · '.config('app.name'))

@section('content')
    <x-auth.shell
        badge="Member access"
        title="Sign in to {{ config('app.name') }}"
        description="Sign in with your username and password or a registered passkey."
    >
        <x-slot:context>
            <h2 class="font-display text-2xl font-bold tracking-[-0.02em]">Before you continue</h2>
            <p class="mt-3 text-sm leading-6 text-neutral-content/70">
                You may need to complete additional identity or security checks required by the alliance.
            </p>

            <ol class="mt-7 space-y-5">
                <x-auth.journey-step
                    number="1"
                    state="current"
                    title="Sign in"
                    description="Use your password or a passkey already registered to your account."
                />
                <x-auth.journey-step
                    number="2"
                    title="Complete required checks"
                    description="You may need to confirm your nation, link Discord, or complete an extra security check."
                />
                <x-auth.journey-step
                    number="3"
                    title="Open the member app"
                    description="Your account access determines which member tools appear."
                />
            </ol>
        </x-slot:context>

        <div class="space-y-6">
            @if(session('status'))
                <div class="alert alert-success items-start" role="status">
                    <x-icon name="o-check-circle" class="mt-0.5 size-5 shrink-0" aria-hidden="true" />
                    <span>{{ session('status') }}</span>
                </div>
            @endif

            <x-auth.error-summary
                id="login-errors"
                title="We could not sign you in. Check the details below and try again."
                :field-ids="[
                    'name' => 'login-name',
                    'password' => 'login-password',
                ]"
            />

            <form method="POST" action="{{ route('login') }}" class="space-y-5">
                @csrf

                <x-auth.field
                    id="login-name"
                    name="name"
                    label="Username"
                    hint="Use the username you chose when you created your account."
                >
                    <input
                        type="text"
                        id="login-name"
                        name="name"
                        value="{{ old('name') }}"
                        @class(['input w-full', 'input-error' => $errors->has('name')])
                        autocomplete="username webauthn"
                        aria-describedby="login-name-help{{ $errors->has('name') ? ' login-name-error' : '' }}"
                        @if($errors->has('name')) aria-invalid="true" @endif
                        required
                        autofocus
                    >
                </x-auth.field>

                <x-auth.field
                    id="login-password"
                    name="password"
                    label="Password"
                    hint="Enter the password for this account."
                >
                    <input
                        type="password"
                        id="login-password"
                        name="password"
                        @class(['input w-full', 'input-error' => $errors->has('password')])
                        autocomplete="current-password"
                        aria-describedby="login-password-help{{ $errors->has('password') ? ' login-password-error' : '' }}"
                        @if($errors->has('password')) aria-invalid="true" @endif
                        required
                    >
                </x-auth.field>

                <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <label for="remember" class="flex cursor-pointer items-start gap-3">
                        <input
                            type="checkbox"
                            id="remember"
                            name="remember"
                            value="1"
                            class="checkbox checkbox-primary checkbox-sm mt-0.5"
                            aria-describedby="remember-help"
                            @checked(old('remember'))
                        >
                        <span>
                            <span class="block text-sm font-medium text-base-content">Keep me signed in</span>
                            <span id="remember-help" class="mt-0.5 block text-xs text-base-content/70">Use this only on a private device.</span>
                        </span>
                    </label>

                    <a class="link link-primary text-sm font-semibold" href="{{ route('password.request') }}">
                        Reset your password
                    </a>
                </div>

                <button type="submit" class="btn btn-primary w-full">Sign in to the member app</button>
            </form>

            <div class="divider text-sm text-base-content/70">or</div>

            <div
                class="space-y-3"
                data-passkey-root
                data-passkey-autofill="true"
                data-passkey-options-url="{{ route('passkey.login-options') }}"
                data-passkey-submit-url="{{ route('passkey.login') }}"
                data-passkey-busy-label="Checking passkey…"
                data-passkey-success-message="Passkey verified. Signing you in…"
            >
                <button
                    type="button"
                    class="btn btn-outline w-full"
                    data-passkey-verify
                    data-passkey-supported-control
                    hidden
                >
                    <span class="loading loading-spinner loading-sm" data-async-button-spinner hidden aria-hidden="true"></span>
                    <span data-async-button-label>Sign in with a passkey</span>
                </button>

                <p class="text-sm text-base-content/70" data-passkey-unsupported hidden>
                    Passkeys are not supported in this browser. You can still sign in with your password.
                </p>

                <div
                    class="alert items-start text-sm"
                    data-passkey-status
                    aria-live="polite"
                    aria-atomic="true"
                    hidden
                ></div>

                <noscript>
                    <p class="text-sm text-base-content/70">Passkey sign-in needs JavaScript. Password sign-in remains available above.</p>
                </noscript>
            </div>
        </div>

        <x-slot:footer>
            Need an account?
            <a class="link link-primary font-semibold" href="{{ route('register') }}">Create your member account</a>
        </x-slot:footer>
    </x-auth.shell>
@endsection
