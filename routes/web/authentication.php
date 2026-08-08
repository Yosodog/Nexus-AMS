<?php

use App\Http\Controllers\DiscordVerificationController;
use App\Http\Controllers\VerificationController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    // Verification
    Route::get('/verify/{code}', [VerificationController::class, 'verify'])->name('verify');
    Route::get('/notverified', [VerificationController::class, 'notVerified'])->name(
        'not_verified'
    );
    Route::post('/resend-verification', [VerificationController::class, 'resendVerification'])
        ->middleware('throttle:verification-resends')
        ->name('verification.resend');

    Route::get('/verify-discord', [DiscordVerificationController::class, 'show'])->name('discord.verify.show');
    Route::post('/verify-discord/regenerate', [DiscordVerificationController::class, 'regenerateToken'])->name(
        'discord.token.regenerate'
    );
    Route::post('/verify-discord/unlink', [DiscordVerificationController::class, 'unlink'])->name(
        'discord.unlink'
    );
});
