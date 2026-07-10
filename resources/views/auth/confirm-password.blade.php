@extends('layouts.public')

@section('title', 'Confirm password · '.config('app.name'))

@section('content')
    <x-auth.shell
        badge="Security check"
        title="Confirm your password"
        description="Re-enter the password for your account. After it is confirmed, you will return to the protected action you requested."
    >
        <x-slot:context>
            <div class="inline-grid size-10 place-items-center rounded-lg bg-neutral-content/10 text-neutral-content" aria-hidden="true">
                <x-icon name="o-lock-closed" class="size-5" />
            </div>
            <h2 class="mt-5 font-display text-2xl font-bold tracking-[-0.02em]">Why we ask again</h2>
            <p class="mt-3 text-sm leading-6 text-neutral-content/70">
                Some actions can reveal security information or change access to your account. A fresh password check helps protect them if an open session is left unattended.
            </p>
            <p class="mt-6 border-t border-neutral-content/15 pt-5 text-xs leading-5 text-neutral-content/70">
                Your requested action will not run until this check succeeds.
            </p>
        </x-slot:context>

        <div class="space-y-6">
            <x-auth.error-summary title="Your password could not be confirmed." />

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
                        @class(['input input-bordered w-full', 'input-error' => $errors->has('password')])
                        autocomplete="current-password"
                        aria-describedby="confirm-current-password-help{{ $errors->has('password') ? ' confirm-current-password-error' : '' }}"
                        @if($errors->has('password')) aria-invalid="true" @endif
                        required
                        autofocus
                    >
                </x-auth.field>

                <button type="submit" class="btn btn-primary w-full">Confirm password and continue</button>
            </form>
        </div>
    </x-auth.shell>
@endsection
