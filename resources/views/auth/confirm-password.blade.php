@extends('layouts.public')

@section('title', 'Confirm your identity · '.config('app.name'))

@section('content')
    <x-auth.shell
        badge="Security check"
        title="Confirm it's you"
        description="Use your current password or a registered passkey. After confirmation, you will return to what you were trying to do."
    >
        <x-slot:context>
            <div class="inline-grid size-10 place-items-center rounded-lg bg-neutral-content/10 text-neutral-content" aria-hidden="true">
                <x-icon name="o-lock-closed" class="size-5" />
            </div>
            <h2 class="mt-5 font-display text-2xl font-bold tracking-[-0.02em]">Why we ask again</h2>
            <p class="mt-3 text-sm leading-6 text-neutral-content/70">
                Some actions can reveal security information or change access to your account. A new check helps protect your account if you leave it signed in.
            </p>
            <p class="mt-6 border-t border-neutral-content/15 pt-5 text-xs leading-5 text-neutral-content/70">
                The task will not continue until this check succeeds.
            </p>
        </x-slot:context>

        <div class="space-y-6">
            <x-auth.error-summary
                id="confirm-password-errors"
                title="Your password could not be confirmed."
                :field-ids="['password' => 'confirm-current-password']"
            />

            <form method="POST" action="{{ url('/user/confirm-password') }}" class="space-y-5">
                @csrf

                <x-auth.field
                    id="confirm-current-password"
                    name="password"
                    label="Current password"
                    hint="Use the password for the account that is currently signed in."
                >
                    <input
                        type="password"
                        id="confirm-current-password"
                        name="password"
                        @class(['input w-full', 'input-error' => $errors->has('password')])
                        autocomplete="current-password"
                        aria-describedby="confirm-current-password-help{{ $errors->has('password') ? ' confirm-current-password-error' : '' }}"
                        @if($errors->has('password')) aria-invalid="true" @endif
                        required
                        autofocus
                    >
                </x-auth.field>

                <button type="submit" class="btn btn-primary w-full">Confirm password and continue</button>
            </form>

            @if(auth()->user()?->hasPasskeysEnabled())
                <div class="divider text-sm text-base-content/70">or</div>

                <div
                    class="space-y-3"
                    data-passkey-root
                    data-passkey-options-url="{{ route('passkey.confirm-options') }}"
                    data-passkey-submit-url="{{ route('passkey.confirm') }}"
                    data-passkey-busy-label="Checking passkey…"
                    data-passkey-success-message="Passkey verified. Returning to your task…"
                >
                    <button
                        type="button"
                        class="btn btn-outline w-full"
                        data-passkey-verify
                        data-passkey-supported-control
                        hidden
                    >
                        <span class="loading loading-spinner loading-sm" data-async-button-spinner hidden aria-hidden="true"></span>
                        <span data-async-button-label>Confirm with a passkey</span>
                    </button>

                    <p class="text-sm text-base-content/70" data-passkey-unsupported hidden>
                        Passkey confirmation is not supported in this browser. Confirm with your password instead.
                    </p>

                    <div
                        class="alert items-start text-sm"
                        data-passkey-status
                        aria-live="polite"
                        aria-atomic="true"
                        hidden
                    ></div>
                </div>
            @endif
        </div>
    </x-auth.shell>
@endsection
