@extends('layouts.public')

@section('title', 'Verify your sign-in · '.config('app.name'))

@section('content')
    <x-auth.shell
        badge="Sign-in security · Final check"
        title="Verify it is you"
        description="Your username and password were accepted. Complete this multi-factor check to finish signing in."
    >
        <x-slot:context>
            <div class="inline-grid size-10 place-items-center rounded-lg bg-neutral-content/10 text-neutral-content" aria-hidden="true">
                <x-icon name="o-shield-check" class="size-5" />
            </div>
            <h2 class="mt-5 font-display text-2xl font-bold tracking-[-0.02em]">Use one verification method</h2>
            <ul class="mt-5 space-y-4 text-sm leading-6 text-neutral-content/70">
                <li class="flex items-start gap-3">
                    <span class="mt-2 size-1.5 shrink-0 rounded-full bg-neutral-content/50" aria-hidden="true"></span>
                    <span><strong class="font-semibold text-neutral-content">Authenticator app:</strong> enter the current six-digit code.</span>
                </li>
                <li class="flex items-start gap-3">
                    <span class="mt-2 size-1.5 shrink-0 rounded-full bg-neutral-content/50" aria-hidden="true"></span>
                    <span><strong class="font-semibold text-neutral-content">Recovery code:</strong> use one unused code saved when you enabled MFA.</span>
                </li>
            </ul>
            <p class="mt-6 border-t border-neutral-content/15 pt-5 text-xs leading-5 text-neutral-content/70">
                Enter one method and leave the other field blank.
            </p>
        </x-slot:context>

        <div class="space-y-6">
            <x-auth.error-summary title="We could not verify those multi-factor credentials." />

            <form method="POST" action="{{ url('/two-factor-challenge') }}" class="space-y-5">
                @csrf

                <fieldset class="space-y-5">
                    <legend class="sr-only">Choose a multi-factor verification method</legend>

                    <x-auth.field
                        id="two-factor-code"
                        name="code"
                        label="Authenticator code"
                        hint="Enter the current six-digit code from your authenticator app."
                        :optional="true"
                    >
                        <input
                            type="text"
                            id="two-factor-code"
                            name="code"
                            value="{{ old('code') }}"
                            @class(['input w-full font-mono tracking-[0.2em]', 'input-error' => $errors->has('code')])
                            inputmode="numeric"
                            autocomplete="one-time-code"
                            aria-describedby="two-factor-code-help{{ $errors->has('code') ? ' two-factor-code-error' : '' }}"
                            @if($errors->has('code')) aria-invalid="true" @endif
                            autofocus
                        >
                    </x-auth.field>

                    <div class="flex items-center gap-3" aria-hidden="true">
                        <span class="h-px flex-1 bg-base-300"></span>
                        <span class="text-xs font-medium text-base-content/70">or</span>
                        <span class="h-px flex-1 bg-base-300"></span>
                    </div>

                    <x-auth.field
                        id="two-factor-recovery-code"
                        name="recovery_code"
                        label="Recovery code"
                        hint="Use one unused recovery code. Each recovery code works once."
                        :optional="true"
                    >
                        <input
                            type="text"
                            id="two-factor-recovery-code"
                            name="recovery_code"
                            value="{{ old('recovery_code') }}"
                            @class(['input w-full font-mono', 'input-error' => $errors->has('recovery_code')])
                            autocomplete="one-time-code"
                            autocapitalize="off"
                            spellcheck="false"
                            aria-describedby="two-factor-recovery-code-help{{ $errors->has('recovery_code') ? ' two-factor-recovery-code-error' : '' }}"
                            @if($errors->has('recovery_code')) aria-invalid="true" @endif
                        >
                    </x-auth.field>
                </fieldset>

                <div class="border-t border-base-300 pt-5">
                    <label for="trust-device" class="flex cursor-pointer items-start gap-3">
                        <input
                            type="checkbox"
                            id="trust-device"
                            name="trust_device"
                            value="1"
                            class="checkbox checkbox-primary checkbox-sm mt-0.5"
                            aria-describedby="trust-device-help"
                            @checked(old('trust_device'))
                        >
                        <span>
                            <span class="block text-sm font-medium text-base-content">Trust this browser for 14 days</span>
                            <span id="trust-device-help" class="mt-0.5 block text-xs leading-5 text-base-content/70">
                                Skip this challenge on this browser. Do not use on a shared or public device.
                            </span>
                        </span>
                    </label>
                </div>

                <button type="submit" class="btn btn-primary w-full">Verify and sign in</button>
            </form>
        </div>
    </x-auth.shell>
@endsection
