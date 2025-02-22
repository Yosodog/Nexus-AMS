@extends('layouts.main')

@section("content")
    <div class="prose w-full max-w-none mb-5">
        <h1 class="text-center flex items-center justify-center gap-2">
            Not Verified
        </h1>
        <p class="text-center">You must verify your account before performing any actions. Please check your messages
            in-game for a verification code.</p>
    </div>
    <div class="flex justify-center">
        <form action="{{ route('verification.resend') }}" method="POST">
            @csrf
            <button type="submit" class="btn btn-primary">Resend Verification Message</button>
        </form>
    </div>
@endsection
