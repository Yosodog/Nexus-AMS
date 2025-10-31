@extends('layouts.main')

@section('content')
    <x-utils.card title="Reset Password" extraClasses="mx-auto w-96">
        <form method="post" action="{{ route('password.update') }}">
            @csrf

            <input type="hidden" name="token" value="{{ $request->route('token') }}">
            <input type="hidden" name="email" value="{{ $request->email }}">

            <label class="input input-bordered flex items-center gap-2 mb-2">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" fill="currentColor" class="w-4 h-4 opacity-70">
                    <path fill-rule="evenodd" d="M14 6a4 4 0 0 1-4.899 3.899l-1.955 1.955a.5.5 0 0 1-.353.146H5v1.5a.5.5 0 0 1-.5.5h-2a.5.5 0 0 1-.5-.5v-2.293a.5.5 0 0 1 .146-.353l3.955-3.955A4 4 0 1 1 14 6Zm-4-2a.75.75 0 0 0 0 1.5.5.5 0 0 1 .5.5.75.75 0 0 0 1.5 0 2 2 0 0 0-2-2Z" clip-rule="evenodd" />
                </svg>
                <input type="password" name="password" class="grow" placeholder="New Password" required autocomplete="new-password" />
            </label>
            @error('password')
                <p class="text-error text-sm mb-2">{{ $message }}</p>
            @enderror

            <label class="input input-bordered flex items-center gap-2 mb-2">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" fill="currentColor" class="w-4 h-4 opacity-70">
                    <path fill-rule="evenodd" d="M14 6a4 4 0 0 1-4.899 3.899l-1.955 1.955a.5.5 0 0 1-.353.146H5v1.5a.5.5 0 0 1-.5.5h-2a.5.5 0 0 1-.5-.5v-2.293a.5.5 0 0 1 .146-.353l3.955-3.955A4 4 0 1 1 14 6Zm-4-2a.75.75 0 0 0 0 1.5.5.5 0 0 1 .5.5.75.75 0 0 0 1.5 0 2 2 0 0 0-2-2Z" clip-rule="evenodd" />
                </svg>
                <input type="password" name="password_confirmation" class="grow" placeholder="Confirm Password" required autocomplete="new-password" />
            </label>
            @error('password_confirmation')
                <p class="text-error text-sm mb-2">{{ $message }}</p>
            @enderror

            <div class="card-actions justify-end mt-4">
                <button type="submit" class="btn btn-primary w-full">Reset Password</button>
            </div>
        </form>
    </x-utils.card>
@endsection
