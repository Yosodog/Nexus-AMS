@extends("layouts.main")

@section("content")
    <x-utils.card title="Welcome back" extraClasses="mx-auto w-full max-w-md">
        <p class="text-sm text-base-content/70">Sign in with your {{ env("APP_NAME") }} username to jump back into your dashboard.</p>

        @if (session('status'))
            <div class="alert alert-success mb-4">
                {{ session('status') }}
            </div>
        @endif

        @if ($errors->any())
            <div class="alert alert-error mb-4">
                <div class="flex flex-col gap-1 text-sm">
                    <span class="font-semibold">We couldn't sign you in.</span>
                    <span class="text-base-content/80">Double-check the details below.</span>
                </div>
            </div>
        @endif

        <form method="post" action="{{ route("login") }}" class="">
            @csrf

            <div class="form-control">
                <label class="label" for="name">
                    <span class="label-text font-medium">Username</span>
                </label>
                <label class="input input-bordered flex items-center gap-2">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"
                         class="w-5 h-5 opacity-70">
                        <path fill-rule="evenodd"
                              d="M7.5 6a4.5 4.5 0 1 1 9 0 4.5 4.5 0 0 1-9 0ZM3.751 20.105a8.25 8.25 0 0 1 16.498 0 .75.75 0 0 1-.437.695A18.683 18.683 0 0 1 12 22.5c-2.786 0-5.433-.608-7.812-1.7a.75.75 0 0 1-.437-.695Z"
                              clip-rule="evenodd"/>
                    </svg>
                    <input type="text" id="name" name="name" class="grow" placeholder="Username"
                           value="{{ old('name') }}" autocomplete="username" required autofocus/>
                </label>
                @error('name')
                    <p class="mt-1 text-sm text-error">{{ $message }}</p>
                @enderror
            </div>

            <div class="form-control">
                <label class="label" for="password">
                    <span class="label-text font-medium">Password</span>
                </label>
                <label class="input input-bordered flex items-center gap-2">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" fill="currentColor"
                         class="w-4 h-4 opacity-70">
                        <path fill-rule="evenodd"
                              d="M14 6a4 4 0 0 1-4.899 3.899l-1.955 1.955a.5.5 0 0 1-.353.146H5v1.5a.5.5 0 0 1-.5.5h-2a.5.5 0 0 1-.5-.5v-2.293a.5.5 0 0 1 .146-.353l3.955-3.955A4 4 0 1 1 14 6Zm-4-2a.75.75 0 0 0 0 1.5.5.5 0 0 1 .5.5.75.75 0 0 0 1.5 0 2 2 0 0 0-2-2Z"
                              clip-rule="evenodd"/>
                    </svg>
                    <input type="password" id="password" name="password" class="grow" placeholder="Password"
                           autocomplete="current-password" required/>
                </label>
                @error('password')
                    <p class="mt-1 text-sm text-error">{{ $message }}</p>
                @enderror
            </div>

            <div class="flex items-center justify-between text-sm">
                <label class="flex items-center gap-2 cursor-pointer select-none">
                    <input type="checkbox" name="remember" class="checkbox checkbox-sm" {{ old('remember') ? 'checked' : '' }}>
                    <span class="text-base-content/80">Keep me signed in</span>
                </label>
                <a class="link link-primary" href="{{ route('password.request') }}">Forgot your password?</a>
            </div>

            <div class="card-actions flex-col gap-2">
                <input type="submit" class="btn btn-primary w-full" value="Log in">
                <p class="text-sm text-base-content/70 text-center">
                    New here?
                    <a class="link link-primary" href="{{ route('register') }}">Create an account</a>
                </p>
            </div>
        </form>
    </x-utils.card>
@endsection
