<?php

use App\Http\Controllers\CityGrantController as UserCityGrantController;
use App\Http\Controllers\GrantController as UserGrantController;
use App\Http\Middleware\BlockWhenPWDown;
use Illuminate\Support\Facades\Route;

// Grants
Route::prefix('grants')->middleware(['auth'])->group(function () {
    // City grants
    Route::get('/city', [UserCityGrantController::class, 'index'])->name('grants.city');
    Route::post('/city', [UserCityGrantController::class, 'request'])->name(
        'grants.city.request'
    )
        ->middleware([BlockWhenPWDown::class, 'throttle:grant-requests']);

    Route::get('/history', [UserGrantController::class, 'history'])->name('grants.history');
    Route::get('{grant:slug}', [UserGrantController::class, 'show'])->name('grants.show_grants');
    Route::post('{grant:slug}/apply', [UserGrantController::class, 'apply'])->name('grants.apply')
        ->middleware([BlockWhenPWDown::class, 'throttle:grant-requests']);
});
