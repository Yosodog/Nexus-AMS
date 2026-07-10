@extends('layouts.public')

@section('title', 'Choose a new password · '.config('app.name'))

@section('content')
    <x-auth.shell
        badge="Account recovery · Step 2"
        title="Choose a new password"
        description="Replace the password for the account linked to this reset request. Reset links expire after 60 minutes and can only be used for the intended account."
    >
        <x-slot:context>
            <h2 class="font-display text-2xl font-bold tracking-[-0.02em]">Recovery path</h2>
            <p class="mt-3 text-sm leading-6 text-neutral-content/70">
                You reached this page through the private link sent to your nation.
            </p>

            <ol class="mt-7 space-y-5">
                <x-auth.journey-step
                    number="1"
                    state="complete"
                    title="Open the reset link"
                    description="The link identified the account for this request."
                />
                <x-auth.journey-step
                    number="2"
                    state="current"
                    title="Set a new password"
                    description="Choose a strong password you do not use elsewhere."
                />
                <x-auth.journey-step
                    number="3"
                    title="Sign in again"
                    description="Use your username and new password to return to the member app."
                />
            </ol>
        </x-slot:context>

        <div class="space-y-6">
            <x-auth.error-summary title="We could not update your password. Check the details below." />

            <form method="POST" action="{{ route('password.update') }}" class="space-y-5">
                @csrf

                <input type="hidden" name="token" value="{{ $request->route('token') }}">
                <input type="hidden" name="email" value="{{ $request->email }}">

                <x-auth.field
                    id="reset-password"
                    name="password"
                    label="New password"
                    hint="Use a long, unique password that meets the current account security requirements."
                >
                    <input
                        type="password"
                        id="reset-password"
                        name="password"
                        @class(['input input-bordered w-full', 'input-error' => $errors->has('password')])
                        autocomplete="new-password"
                        aria-describedby="reset-password-help{{ $errors->has('password') ? ' reset-password-error' : '' }}"
                        @if($errors->has('password')) aria-invalid="true" @endif
                        required
                        autofocus
                    >
                </x-auth.field>

                <x-auth.field
                    id="reset-password-confirmation"
                    name="password_confirmation"
                    label="Confirm new password"
                    hint="Enter the same new password again."
                >
                    <input
                        type="password"
                        id="reset-password-confirmation"
                        name="password_confirmation"
                        @class(['input input-bordered w-full', 'input-error' => $errors->has('password_confirmation')])
                        autocomplete="new-password"
                        aria-describedby="reset-password-confirmation-help{{ $errors->has('password_confirmation') ? ' reset-password-confirmation-error' : '' }}"
                        @if($errors->has('password_confirmation')) aria-invalid="true" @endif
                        required
                    >
                </x-auth.field>

                <button type="submit" class="btn btn-primary w-full">Save new password</button>
            </form>
        </div>

        <x-slot:footer>
            If this link has expired,
            <a class="link link-primary font-semibold" href="{{ route('password.request') }}">request a new reset link</a>.
        </x-slot:footer>
    </x-auth.shell>
@endsection
