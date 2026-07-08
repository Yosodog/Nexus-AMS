@extends('layouts.main')

@section("content")
    <div class="prose w-full max-w-none mb-5">
        <h1 class="text-center flex items-center justify-center gap-2">
            Not verified
        </h1>
        <p class="text-center">Verify your account before using the app. Check your in-game messages for the verification code.</p>
    </div>
    <div class="flex justify-center">
        <form action="{{ route('verification.resend') }}" method="POST">
            @csrf
            <button type="submit" class="btn btn-primary">Resend verification message</button>
        </form>
    </div>
@endsection
