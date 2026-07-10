@extends('layouts.public')

@section('title', 'Reset password · '.config('app.name'))

@section('content')
    <x-auth.shell
        badge="Account recovery · Step 1"
        title="Reset your password"
        description="Enter your nation ID. If it matches an account, we will send a time-limited reset link to that nation's in-game messages."
    >
        <x-slot:context>
            <h2 class="font-display text-2xl font-bold tracking-[-0.02em]">Recovery path</h2>
            <p class="mt-3 text-sm leading-6 text-neutral-content/70">
                Password recovery stays tied to the Politics & War nation associated with your account.
            </p>

            <ol class="mt-7 space-y-5">
                <x-auth.journey-step
                    number="1"
                    state="current"
                    title="Identify your account"
                    description="Enter the nation ID connected to your member account."
                />
                <x-auth.journey-step
                    number="2"
                    title="Open the in-game message"
                    description="Use the private reset link sent to that nation."
                />
                <x-auth.journey-step
                    number="3"
                    title="Choose a new password"
                    description="The reset link expires after 60 minutes."
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

            <x-auth.error-summary title="We could not process that nation ID." />

            <form method="POST" action="{{ route('password.email') }}" class="space-y-6">
                @csrf

                <x-auth.field
                    id="recovery-nation-id"
                    name="nation_id"
                    label="Politics & War nation ID"
                    hint="Enter the numeric ID from your nation URL. For privacy, the result will not confirm whether an account exists."
                >
                    <input
                        type="number"
                        id="recovery-nation-id"
                        name="nation_id"
                        value="{{ old('nation_id') }}"
                        min="1"
                        inputmode="numeric"
                        @class(['input w-full', 'input-error' => $errors->has('nation_id')])
                        aria-describedby="recovery-nation-id-help{{ $errors->has('nation_id') ? ' recovery-nation-id-error' : '' }}"
                        @if($errors->has('nation_id')) aria-invalid="true" @endif
                        required
                        autofocus
                    >
                </x-auth.field>

                <button type="submit" class="btn btn-primary w-full">Send password reset link</button>
            </form>
        </div>

        <x-slot:footer>
            Remembered your password?
            <a class="link link-primary font-semibold" href="{{ route('login') }}">Return to sign in</a>
        </x-slot:footer>
    </x-auth.shell>
@endsection
