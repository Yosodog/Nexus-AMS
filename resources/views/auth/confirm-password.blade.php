@extends('layouts.main')

@section('content')
    <x-utils.card title="Confirm password" extraClasses="mx-auto w-full max-w-md">
        <p class="text-sm text-base-content/70">
            Confirm your password to continue with this security-sensitive action.
        </p>

        @if($errors->any())
            <div class="alert alert-error mb-4">
                <span>Your password could not be confirmed. Please try again.</span>
            </div>
        @endif

        <form method="POST" action="{{ url('/user/confirm-password') }}" class="space-y-4">
            @csrf

            <div class="form-control">
                <label class="label" for="password">
                    <span class="label-text font-medium">Password</span>
                </label>
                <input
                    type="password"
                    id="password"
                    name="password"
                    class="input input-bordered w-full"
                    autocomplete="current-password"
                    required
                >
                @error('password') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
            </div>

            <button type="submit" class="btn btn-primary w-full">Confirm password</button>
        </form>
    </x-utils.card>
@endsection
