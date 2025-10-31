@extends("layouts.main")

@section("content")
    <x-utils.card title="Login" extraClasses="mx-auto w-96">
        <form method="post" action="{{ route("login") }}">
            @csrf
            <div class="items-center mt-2">
                <label class="input input-bordered flex items-center gap-2 mb-2">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"
                         class="w-4 h-4 opacity-70">
                        <path fill-rule="evenodd"
                              d="M7.5 6a4.5 4.5 0 1 1 9 0 4.5 4.5 0 0 1-9 0ZM3.751 20.105a8.25 8.25 0 0 1 16.498 0 .75.75 0 0 1-.437.695A18.683 18.683 0 0 1 12 22.5c-2.786 0-5.433-.608-7.812-1.7a.75.75 0 0 1-.437-.695Z"
                              clip-rule="evenodd"/>
                    </svg>
                    <input type="text" class="grow" name="name" placeholder="Username"/>
                </label>
                <label class="input input-bordered flex items-center gap-2 mb-2">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" fill="currentColor"
                         class="w-4 h-4 opacity-70">
                        <path fill-rule="evenodd"
                              d="M14 6a4 4 0 0 1-4.899 3.899l-1.955 1.955a.5.5 0 0 1-.353.146H5v1.5a.5.5 0 0 1-.5.5h-2a.5.5 0 0 1-.5-.5v-2.293a.5.5 0 0 1 .146-.353l3.955-3.955A4 4 0 1 1 14 6Zm-4-2a.75.75 0 0 0 0 1.5.5.5 0 0 1 .5.5.75.75 0 0 0 1.5 0 2 2 0 0 0-2-2Z"
                              clip-rule="evenodd"/>
                    </svg>
                    <input type="password" name="password" class="grow" placeholder="Password"/>
                </label>
            </div>
            <div class="flex justify-between items-center mt-2">
                <a class="link link-primary text-sm" href="{{ route('password.request') }}">Forgot your password?</a>
            </div>
            <div class="card-actions justify-end">
                <input type="submit" class="btn btn-primary w-full" value="Login">
            </div>
        </form>
    </x-utils.card>
@endsection
