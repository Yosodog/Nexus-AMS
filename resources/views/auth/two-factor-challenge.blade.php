@extends('layouts.main')

@section('content')
    <x-utils.card title="Two-factor challenge" extraClasses="mx-auto w-full max-w-md">
        <p class="text-sm text-base-content/70">
            Enter the 6-digit code from your authenticator app, or use one of your recovery codes.
        </p>

        @if($errors->any())
            <div class="alert alert-error mb-4">
                <span>We could not verify your two-factor credentials. Try again.</span>
            </div>
        @endif

        <form method="POST" action="{{ url('/two-factor-challenge') }}" class="space-y-4">
            @csrf

            <div class="form-control">
                <label class="label" for="code">
                    <span class="label-text font-medium">Authentication code</span>
                </label>
                <input
                    type="text"
                    id="code"
                    name="code"
                    value="{{ old('code') }}"
                    class="input input-bordered w-full"
                    inputmode="numeric"
                    autocomplete="one-time-code"
                    placeholder="123456"
                >
                @error('code') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
            </div>

            <div class="divider">or</div>

            <div class="form-control">
                <label class="label" for="recovery_code">
                    <span class="label-text font-medium">Recovery code</span>
                </label>
                <input
                    type="text"
                    id="recovery_code"
                    name="recovery_code"
                    value="{{ old('recovery_code') }}"
                    class="input input-bordered w-full"
                    autocomplete="one-time-code"
                    placeholder="xxxx-xxxx"
                >
                @error('recovery_code') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
            </div>

            <label class="label cursor-pointer justify-start gap-2">
                <input type="checkbox" name="trust_device" value="1" class="checkbox checkbox-sm" @checked(old('trust_device'))>
                <span class="label-text">Trust this device for 14 days</span>
            </label>

            <button type="submit" class="btn btn-primary w-full">Verify and continue</button>
        </form>
    </x-utils.card>
@endsection
