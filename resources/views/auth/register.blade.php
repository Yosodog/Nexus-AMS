@extends('layouts.public')

@section('title', 'Create account · '.config('app.name'))

@section('content')
    <x-auth.shell
        badge="Account setup · Step 1"
        title="Create your member account"
        description="Connect your sign-in credentials to the Politics & War nation you manage. We verify nation ownership before member tools become available."
    >
        <x-slot:context>
            <h2 class="font-display text-2xl font-bold tracking-[-0.02em]">Your access path</h2>
            <p class="mt-3 text-sm leading-6 text-neutral-content/70">
                Setup begins here and continues in the services used by your alliance.
            </p>

            <ol class="mt-7 space-y-5">
                <x-auth.journey-step
                    number="1"
                    state="current"
                    title="Create your account"
                    description="Choose credentials and identify the nation you manage."
                />
                <x-auth.journey-step
                    number="2"
                    title="Verify your nation"
                    description="Open the message sent to your nation in Politics & War."
                />
                <x-auth.journey-step
                    number="3"
                    title="Complete access checks"
                    description="Your alliance may also require Discord linking or multi-factor authentication."
                />
            </ol>
        </x-slot:context>

        <div class="space-y-6">
            <x-auth.error-summary title="Some account details need your attention." />

            <form method="POST" action="{{ route('register') }}" class="space-y-7">
                @csrf

                <section aria-labelledby="identity-fields-title">
                    <h2 id="identity-fields-title" class="font-display text-xl font-bold text-base-content">Account and nation</h2>
                    <p class="mt-1 text-sm leading-6 text-base-content/70">
                        All fields are required. Registration is limited to eligible alliance nations.
                    </p>

                    <div class="mt-5 grid gap-5 sm:grid-cols-2">
                        <x-auth.field
                            id="register-name"
                            name="name"
                            label="Username"
                            hint="Your sign-in name. It may also be visible to other members."
                        >
                            <input
                                type="text"
                                id="register-name"
                                name="name"
                                value="{{ old('name') }}"
                                @class(['input input-bordered w-full', 'input-error' => $errors->has('name')])
                                autocomplete="username"
                                aria-describedby="register-name-help{{ $errors->has('name') ? ' register-name-error' : '' }}"
                                @if($errors->has('name')) aria-invalid="true" @endif
                                required
                                autofocus
                            >
                        </x-auth.field>

                        <x-auth.field
                            id="register-email"
                            name="email"
                            label="Email address"
                            hint="Keep this current for account notices."
                        >
                            <input
                                type="email"
                                id="register-email"
                                name="email"
                                value="{{ old('email') }}"
                                @class(['input input-bordered w-full', 'input-error' => $errors->has('email')])
                                autocomplete="email"
                                aria-describedby="register-email-help{{ $errors->has('email') ? ' register-email-error' : '' }}"
                                @if($errors->has('email')) aria-invalid="true" @endif
                                required
                            >
                        </x-auth.field>

                        <x-auth.field
                            id="register-nation-id"
                            name="nation_id"
                            label="Politics & War nation ID"
                            hint="The numeric ID in your nation URL. We use it to verify the nation you manage."
                        >
                            <input
                                type="number"
                                id="register-nation-id"
                                name="nation_id"
                                value="{{ old('nation_id') }}"
                                min="1"
                                inputmode="numeric"
                                @class(['input input-bordered w-full', 'input-error' => $errors->has('nation_id')])
                                aria-describedby="register-nation-id-help{{ $errors->has('nation_id') ? ' register-nation-id-error' : '' }}"
                                @if($errors->has('nation_id')) aria-invalid="true" @endif
                                required
                            >
                        </x-auth.field>
                    </div>
                </section>

                <section class="border-t border-base-300 pt-6" aria-labelledby="password-fields-title">
                    <h2 id="password-fields-title" class="font-display text-xl font-bold text-base-content">Secure your account</h2>
                    <p class="mt-1 text-sm leading-6 text-base-content/70">Use a long, unique password that you do not use elsewhere.</p>

                    <div class="mt-5 grid gap-5 sm:grid-cols-2">
                        <x-auth.field
                            id="register-password"
                            name="password"
                            label="Password"
                            hint="Your password must meet the current account security requirements."
                        >
                            <input
                                type="password"
                                id="register-password"
                                name="password"
                                @class(['input input-bordered w-full', 'input-error' => $errors->has('password')])
                                autocomplete="new-password"
                                aria-describedby="register-password-help{{ $errors->has('password') ? ' register-password-error' : '' }}"
                                @if($errors->has('password')) aria-invalid="true" @endif
                                required
                            >
                        </x-auth.field>

                        <x-auth.field
                            id="register-password-confirmation"
                            name="password_confirmation"
                            label="Confirm password"
                            hint="Enter the same password again."
                        >
                            <input
                                type="password"
                                id="register-password-confirmation"
                                name="password_confirmation"
                                @class(['input input-bordered w-full', 'input-error' => $errors->has('password_confirmation')])
                                autocomplete="new-password"
                                aria-describedby="register-password-confirmation-help{{ $errors->has('password_confirmation') ? ' register-password-confirmation-error' : '' }}"
                                @if($errors->has('password_confirmation')) aria-invalid="true" @endif
                                required
                            >
                        </x-auth.field>
                    </div>
                </section>

                <div class="alert alert-info items-start" role="note">
                    <x-icon name="o-information-circle" class="mt-0.5 size-5 shrink-0" aria-hidden="true" />
                    <p class="text-sm leading-6">
                        <span class="font-semibold">Next:</span>
                        we will send an in-game verification message to the nation above. Open its link to continue setup.
                    </p>
                </div>

                <button type="submit" class="btn btn-primary w-full">Create member account</button>
            </form>
        </div>

        <x-slot:footer>
            Already have an account?
            <a class="link link-primary font-semibold" href="{{ route('login') }}">Sign in</a>
        </x-slot:footer>
    </x-auth.shell>
@endsection
