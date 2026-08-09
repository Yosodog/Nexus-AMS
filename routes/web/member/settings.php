<?php

use App\Http\Controllers\AlertSubscriptionController;
use App\Http\Controllers\ApiDocsController;
use App\Http\Controllers\DiscordBotGuideController;
use App\Http\Controllers\LeaderboardsController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

// User settings
Route::get('/user/settings', [UserController::class, 'settings'])->name('user.settings');
Route::post('/user/settings/update', [UserController::class, 'updateSettings'])->name(
    'user.settings.update'
);
Route::post('/user/settings/discord-notifications', [UserController::class, 'updateDiscordNotificationPreferences'])
    ->name('user.settings.discord-notifications');
Route::get('/user/settings/mfa-secrets', [UserController::class, 'showMfaSecrets'])
    ->middleware('password.confirm')
    ->name('user.settings.mfa-secrets');
Route::post('/user/settings/trusted-devices/{trustedDevice}/revoke', [UserController::class, 'revokeTrustedDevice'])
    ->middleware('password.confirm')
    ->name('user.settings.trusted-devices.revoke');
Route::post('/user/settings/trusted-devices/revoke-all', [UserController::class, 'revokeAllTrustedDevices'])
    ->middleware('password.confirm')
    ->name('user.settings.trusted-devices.revoke-all');
Route::post('/user/settings/api-tokens', [UserController::class, 'storeApiToken'])->name(
    'user.settings.api-tokens.store'
);
Route::post('/user/settings/api-tokens/{tokenId}/regenerate', [UserController::class, 'regenerateApiToken'])->name(
    'user.settings.api-tokens.regenerate'
);
Route::post('/user/settings/api-tokens/{tokenId}/revoke', [UserController::class, 'revokeApiToken'])->name(
    'user.settings.api-tokens.revoke'
);
Route::get('/user/settings/api-docs', ApiDocsController::class)->name('user.settings.api-docs');
Route::get('/user/discord-bot-guide', DiscordBotGuideController::class)->name('user.discord-bot-guide');

// Custom alerts and watchlists
Route::get('/user/alerts', [AlertSubscriptionController::class, 'index'])->name('user.alerts.index');
Route::post('/user/alerts', [AlertSubscriptionController::class, 'store'])->name('user.alerts.store');
Route::put('/user/alerts/settings', [AlertSubscriptionController::class, 'updateSettings'])
    ->name('user.alerts.settings.update');
Route::patch('/user/alerts/activity/{alertDelivery}/read', [AlertSubscriptionController::class, 'markActivityRead'])
    ->name('user.alerts.activity.read');
Route::put('/user/alerts/{alertSubscription}', [AlertSubscriptionController::class, 'update'])
    ->name('user.alerts.update');
Route::patch('/user/alerts/{alertSubscription}/status', [AlertSubscriptionController::class, 'updateStatus'])
    ->name('user.alerts.status');
Route::post('/user/alerts/{alertSubscription}/test', [AlertSubscriptionController::class, 'test'])
    ->name('user.alerts.test');
Route::delete('/user/alerts/{alertSubscription}', [AlertSubscriptionController::class, 'destroy'])
    ->name('user.alerts.destroy');

// User dashboard
Route::get('/user/dashboard', [UserController::class, 'dashboard'])->name('user.dashboard');
Route::get('/leaderboards/{board?}', LeaderboardsController::class)
    ->middleware('throttle:raid-leaderboards')
    ->name('leaderboards.index');
