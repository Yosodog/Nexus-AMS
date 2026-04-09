@extends("layouts.main")

@section("content")
    <section class="mx-auto w-full max-w-5xl">
        <div class="grid gap-6 lg:grid-cols-2">
            <div class="relative overflow-hidden rounded-2xl border border-primary/20 bg-gradient-to-br from-primary/20 via-base-100 to-base-100 p-6 sm:p-8">
                <div class="absolute -right-12 -top-12 h-44 w-44 rounded-full bg-primary/20 blur-3xl"></div>
                <div class="absolute -bottom-16 -left-10 h-48 w-48 rounded-full bg-secondary/20 blur-3xl"></div>

                <div class="relative space-y-4">
                    <p class="text-xs font-semibold uppercase tracking-[0.2em] text-primary">Alliance Portal</p>
                    <h1 class="text-3xl font-black leading-tight sm:text-4xl">
                        Welcome back to {{ config('app.name') }}
                    </h1>
                    <p class="text-base text-base-content/70">
                        Sign in to manage accounts, track readiness, and coordinate support without leaving your dashboard.
                    </p>

                    <div class="space-y-3 pt-2">
                        <div class="flex items-start gap-3">
                            <x-icon name="o-shield-check" class="mt-0.5 size-5 text-success" />
                            <p class="text-sm text-base-content/80">Secure sign-in with your existing account and permissions.</p>
                        </div>
                        <div class="flex items-start gap-3">
                            <x-icon name="o-chart-bar" class="mt-0.5 size-5 text-primary" />
                            <p class="text-sm text-base-content/80">Live financial, military, and tax snapshots on one screen.</p>
                        </div>
                        <div class="flex items-start gap-3">
                            <x-icon name="o-bolt" class="mt-0.5 size-5 text-warning" />
                            <p class="text-sm text-base-content/80">Fast access to grants, loans, defense, and coordination tools.</p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="rounded-2xl border border-base-300 bg-base-100 p-6 shadow-lg sm:p-8">
                <div class="mb-6">
                    <h2 class="text-2xl font-bold">Sign in</h2>
                    <p class="mt-1 text-sm text-base-content/60">Use your username and password to continue.</p>
                </div>

                @if (session('status'))
                    <x-alert class="alert-success mb-4" icon="o-check-circle">
                        {{ session('status') }}
                    </x-alert>
                @endif

                @if ($errors->any())
                    <x-alert class="alert-error mb-4" icon="o-exclamation-triangle">
                        <span class="font-semibold">We couldn’t sign you in.</span>
                        <span class="text-sm text-base-content/80">Check your credentials and try again.</span>
                    </x-alert>
                @endif

                <form method="post" action="{{ route("login") }}" class="space-y-4">
                    @csrf

                    <div class="space-y-1">
                        <label class="text-sm font-semibold" for="name">Username</label>
                        <label class="input input-bordered flex items-center gap-2">
                            <x-icon name="o-user" class="size-4 text-base-content/60" />
                            <input
                                type="text"
                                id="name"
                                name="name"
                                class="grow"
                                placeholder="Enter your username"
                                value="{{ old('name') }}"
                                autocomplete="username"
                                required
                                autofocus
                            />
                        </label>
                        @error('name')
                            <p class="text-sm text-error">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="space-y-1">
                        <label class="text-sm font-semibold" for="password">Password</label>
                        <label class="input input-bordered flex items-center gap-2">
                            <x-icon name="o-lock-closed" class="size-4 text-base-content/60" />
                            <input
                                type="password"
                                id="password"
                                name="password"
                                class="grow"
                                placeholder="Enter your password"
                                autocomplete="current-password"
                                required
                            />
                        </label>
                        @error('password')
                            <p class="text-sm text-error">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="flex items-center justify-between text-sm">
                        <label class="flex cursor-pointer items-center gap-2">
                            <input type="checkbox" name="remember" class="checkbox checkbox-sm checkbox-primary" {{ old('remember') ? 'checked' : '' }}>
                            <span class="text-base-content/80">Keep me signed in</span>
                        </label>
                        <a class="link link-primary" href="{{ route('password.request') }}">Forgot password?</a>
                    </div>

                    <button type="submit" class="btn btn-primary w-full">Log in</button>

                    <p class="text-center text-sm text-base-content/70">
                        New here?
                        <a class="link link-primary font-semibold" href="{{ route('register') }}">Create an account</a>
                    </p>
                </form>
            </div>
        </div>
    </section>
@endsection
