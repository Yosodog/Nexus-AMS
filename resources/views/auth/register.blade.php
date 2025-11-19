@extends("layouts.main")

@section("content")
    <x-utils.card title="Create your account" extraClasses="mx-auto w-full max-w-2xl">
        <p class="mb-4 text-sm text-base-content/70">Join Nexus-AMS to manage your nation and alliance tools in one place. We’ll send a quick verification to confirm your nation.</p>

        @if ($errors->any())
            <div class="alert alert-error mb-4">
                <div class="flex flex-col gap-1 text-sm">
                    <span class="font-semibold">We need a bit more info.</span>
                    <span class="text-base-content/80">Check the highlighted fields and try again.</span>
                </div>
            </div>
        @endif

        <form method="post" action="{{ route("register") }}" class="space-y-4">
            @csrf

            <div class="grid gap-4 md:grid-cols-2">
                <div class="form-control">
                    <label class="label" for="name">
                        <span class="label-text font-medium">Username</span>
                        <span class="label-text-alt text-base-content/60">Displayed to other members</span>
                    </label>
                    <label class="input input-bordered flex items-center gap-2">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" fill="currentColor"
                             class="w-4 h-4 opacity-70">
                            <path fill-rule="evenodd"
                                  d="M7.5 6a4.5 4.5 0 1 1 9 0 4.5 4.5 0 0 1-9 0ZM3.751 20.105a8.25 8.25 0 0 1 16.498 0 .75.75 0 0 1-.437.695A18.683 18.683 0 0 1 12 22.5c-2.786 0-5.433-.608-7.812-1.7a.75.75 0 0 1-.437-.695Z"
                                  clip-rule="evenodd"/>
                        </svg>
                        <input type="text" class="grow" id="name" name="name" placeholder="Username"
                               value="{{ old('name') }}" autocomplete="username" required/>
                    </label>
                    @error('name')
                        <p class="mt-1 text-sm text-error">{{ $message }}</p>
                    @enderror
                </div>

                <div class="form-control">
                    <label class="label" for="email">
                        <span class="label-text font-medium">Email</span>
                        <span class="label-text-alt text-base-content/60">For account alerts</span>
                    </label>
                    <label class="input input-bordered flex items-center gap-2">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" fill="currentColor"
                             class="w-4 h-4 opacity-70">
                            <path d="M2.5 3A1.5 1.5 0 0 0 1 4.5v.793c.026.009.051.02.076.032L7.674 8.51c.206.1.446.1.652 0l6.598-3.185A.755.755 0 0 1 15 5.293V4.5A1.5 1.5 0 0 0 13.5 3h-11Z"/>
                            <path d="M15 6.954 8.978 9.86a2.25 2.25 0 0 1-1.956 0L1 6.954V11.5A1.5 1.5 0 0 0 2.5 13h11a1.5 1.5 0 0 0 1.5-1.5V6.954Z"/>
                        </svg>
                        <input type="email" class="grow" id="email" name="email" placeholder="Email"
                               value="{{ old('email') }}" autocomplete="email" required/>
                    </label>
                    @error('email')
                        <p class="mt-1 text-sm text-error">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            <div class="grid gap-4 md:grid-cols-2">
                <div class="form-control">
                    <label class="label" for="nation_id">
                        <span class="label-text font-medium">Nation ID</span>
                        <span class="label-text-alt text-base-content/60">Used to verify alliance membership</span>
                    </label>
                    <label class="input input-bordered flex items-center gap-2">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" fill="currentColor"
                             class="w-4 h-4 opacity-70">
                            <path fill-rule="evenodd"
                                  d="M4.5 3.75a3 3 0 0 0-3 3v10.5a3 3 0 0 0 3 3h15a3 3 0 0 0 3-3V6.75a3 3 0 0 0-3-3h-15Zm4.125 3a2.25 2.25 0 1 0 0 4.5 2.25 2.25 0 0 0 0-4.5Zm-3.873 8.703a4.126 4.126 0 0 1 7.746 0 .75.75 0 0 1-.351.92 7.47 7.47 0 0 1-3.522.877 7.47 7.47 0 0 1-3.522-.877.75.75 0 0 1-.351-.92ZM15 8.25a.75.75 0 0 0 0 1.5h3.75a.75.75 0 0 0 0-1.5H15ZM14.25 12a.75.75 0 0 1 .75-.75h3.75a.75.75 0 0 1 0 1.5H15a.75.75 0 0 1-.75-.75Zm.75 2.25a.75.75 0 0 0 0 1.5h3.75a.75.75 0 0 0 0-1.5H15Z"
                                  clip-rule="evenodd"/>
                        </svg>
                        <input type="number" class="grow" id="nation_id" name="nation_id" placeholder="Nation ID"
                               value="{{ old('nation_id') }}" min="1" inputmode="numeric" required/>
                    </label>
                    @error('nation_id')
                        <p class="mt-1 text-sm text-error">{{ $message }}</p>
                    @enderror
                </div>

                <div class="space-y-4">
                    <div class="form-control">
                        <label class="label" for="password">
                            <span class="label-text font-medium">Password</span>
                            <span class="label-text-alt text-base-content/60">Make it strong and unique</span>
                        </label>
                        <label class="input input-bordered flex items-center gap-2">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" fill="currentColor"
                                 class="w-4 h-4 opacity-70">
                                <path fill-rule="evenodd"
                                      d="M14 6a4 4 0 0 1-4.899 3.899l-1.955 1.955a.5.5 0 0 1-.353.146H5v1.5a.5.5 0 0 1-.5.5h-2a.5.5 0 0 1-.5-.5v-2.293a.5.5 0 0 1 .146-.353l3.955-3.955A4 4 0 1 1 14 6Zm-4-2a.75.75 0 0 0 0 1.5.5.5 0 0 1 .5.5.75.75 0 0 0 1.5 0 2 2 0 0 0-2-2Z"
                                      clip-rule="evenodd"/>
                            </svg>
                            <input type="password" class="grow" id="password" name="password" placeholder="Password"
                                   autocomplete="new-password" required/>
                        </label>
                        @error('password')
                            <p class="mt-1 text-sm text-error">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="form-control">
                        <label class="label" for="password_confirmation">
                            <span class="label-text font-medium">Confirm password</span>
                        </label>
                        <label class="input input-bordered flex items-center gap-2">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" fill="currentColor"
                                 class="w-4 h-4 opacity-70">
                                <path fill-rule="evenodd"
                                      d="M14 6a4 4 0 0 1-4.899 3.899l-1.955 1.955a.5.5 0 0 1-.353.146H5v1.5a.5.5 0 0 1-.5.5h-2a.5.5 0 0 1-.5-.5v-2.293a.5.5 0 0 1 .146-.353l3.955-3.955A4 4 0 1 1 14 6Zm-4-2a.75.75 0 0 0 0 1.5.5.5 0 0 1 .5.5.75.75 0 0 0 1.5 0 2 2 0 0 0-2-2Z"
                                      clip-rule="evenodd"/>
                            </svg>
                            <input type="password" class="grow" id="password_confirmation" name="password_confirmation"
                                   placeholder="Confirm password" autocomplete="new-password" required/>
                        </label>
                    </div>
                </div>
            </div>

            <div class="card-actions flex-col gap-2">
                <input type="submit" class="btn btn-primary w-full" value="Register">
                <p class="text-sm text-base-content/70 text-center">
                    Already have an account?
                    <a class="link link-primary" href="{{ route('login') }}">Log in</a>
                </p>
            </div>
        </form>
    </x-utils.card>
@endsection
