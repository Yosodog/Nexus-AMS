@extends('layouts.public')

@section('title', 'Verify your nation · '.config('app.name'))

@section('content')
    <x-auth.shell
        badge="Account setup · Step 2"
        title="Verify your nation"
        description="We sent a verification message to your nation in Politics & War. Open that message and select its “Verify account” link to continue."
    >
        <x-slot:context>
            <h2 class="font-display text-2xl font-bold tracking-[-0.02em]">Your access path</h2>
            <p class="mt-3 text-sm leading-6 text-neutral-content/70">
                Nation verification confirms that the signed-in account belongs with the nation used during registration.
            </p>

            <ol class="mt-7 space-y-5">
                <x-auth.journey-step
                    number="1"
                    state="complete"
                    title="Create your account"
                    description="Your credentials and nation ID were saved."
                />
                <x-auth.journey-step
                    number="2"
                    state="current"
                    title="Verify your nation"
                    description="Use the private link in your Politics & War message."
                />
                <x-auth.journey-step
                    number="3"
                    title="Complete access checks"
                    description="The system will show any required Discord or MFA step next."
                />
            </ol>
        </x-slot:context>

        <div class="space-y-6">
            <div class="alert alert-info items-start" role="status">
                <x-icon name="o-envelope" class="mt-0.5 size-5 shrink-0" aria-hidden="true" />
                <div>
                    <p class="font-semibold">Check your in-game messages</p>
                    <p class="mt-1 text-sm leading-6">
                        Look for a message titled “Verify your account.” You do not need to copy or enter the verification code on this page.
                    </p>
                </div>
            </div>

            <section aria-labelledby="verification-instructions-title">
                <h2 id="verification-instructions-title" class="font-display text-xl font-bold text-base-content">Finish verification</h2>
                <ol class="mt-4 space-y-4 text-sm leading-6 text-base-content/70">
                    <li class="flex items-start gap-3">
                        <span class="inline-grid size-6 shrink-0 place-items-center rounded-full bg-base-200 text-xs font-bold text-base-content" aria-hidden="true">1</span>
                        <span>Open the in-game messages for the nation you registered.</span>
                    </li>
                    <li class="flex items-start gap-3">
                        <span class="inline-grid size-6 shrink-0 place-items-center rounded-full bg-base-200 text-xs font-bold text-base-content" aria-hidden="true">2</span>
                        <span>Open the verification message and select <strong class="font-semibold text-base-content">Verify account</strong>.</span>
                    </li>
                    <li class="flex items-start gap-3">
                        <span class="inline-grid size-6 shrink-0 place-items-center rounded-full bg-base-200 text-xs font-bold text-base-content" aria-hidden="true">3</span>
                        <span>Return here if the link asks you to sign in, then open it again.</span>
                    </li>
                </ol>
            </section>

            <form action="{{ route('verification.resend') }}" method="POST" class="border-t border-base-300 pt-6">
                @csrf
                <button type="submit" class="btn btn-primary w-full sm:w-auto">Send a new verification message</button>
                <p class="mt-3 text-xs leading-5 text-base-content/70">
                    You can request a new message once per minute. A new message replaces the verification link sent previously.
                </p>
            </form>
        </div>

        <x-slot:footer>
            You can leave this page while you check Politics & War; your account setup will remain pending.
        </x-slot:footer>
    </x-auth.shell>
@endsection
