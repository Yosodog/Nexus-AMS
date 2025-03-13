@extends('layouts.main')

@section('content')
    <div class="max-w-lg mx-auto mt-10">
        <x-utils.card title="User Settings">
            <form method="POST" action="{{ route('user.settings.update') }}">
                @csrf

                <div class="mb-4">
                    <label class="label" for="name">Name</label>
                    <input type="text" id="name" name="name" value="{{ old('name', $user->name) }}"
                           class="input input-bordered w-full" required>
                    @error('name') <p class="text-red-500 text-sm">{{ $message }}</p> @enderror
                </div>

                <div class="mb-4">
                    <label class="label" for="email">Email</label>
                    <input type="email" id="email" name="email" value="{{ old('email', $user->email) }}"
                           class="input input-bordered w-full" required>
                    @error('email') <p class="text-red-500 text-sm">{{ $message }}</p> @enderror
                </div>

                <div class="mb-4">
                    <label class="label" for="password">New Password (leave blank to keep current)</label>
                    <input type="password" id="password" name="password" class="input input-bordered w-full">
                    @error('password') <p class="text-red-500 text-sm">{{ $message }}</p> @enderror
                </div>

                <div class="mb-4">
                    <label class="label" for="password_confirmation">Confirm New Password</label>
                    <input type="password" id="password_confirmation" name="password_confirmation"
                           class="input input-bordered w-full">
                </div>

                <button type="submit" class="btn btn-primary w-full">Update Settings</button>
            </form>
        </x-utils.card>
    </div>
@endsection