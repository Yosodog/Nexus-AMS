@extends('layouts.main')

@section('content')
    <x-utils.card title="Forgot Password" extraClasses="mx-auto w-96">
        <p class="mb-4 text-sm text-base-content/70">
            Enter your nation ID and we will send an in-game message with a link to reset your password.
        </p>

        @if (session('status'))
            <div class="alert alert-success mb-4">
                {{ session('status') }}
            </div>
        @endif

        <form method="post" action="{{ route('password.email') }}">
            @csrf
            <label class="input input-bordered flex items-center gap-2 mb-2">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 opacity-70">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M7.5 21h9M12 17.25a4.5 4.5 0 0 0 4.5-4.5V9a4.5 4.5 0 1 0-9 0v3.75a4.5 4.5 0 0 0 4.5 4.5Z" />
                </svg>
                <input type="number" min="1" class="grow" name="nation_id" value="{{ old('nation_id') }}" placeholder="Nation ID" required />
            </label>
            @error('nation_id')
                <p class="text-error text-sm mb-2">{{ $message }}</p>
            @enderror

            <div class="card-actions justify-end mt-4">
                <button type="submit" class="btn btn-primary w-full">Send Reset Link</button>
            </div>
        </form>
    </x-utils.card>
@endsection
