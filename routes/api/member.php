<?php

use App\Http\Controllers\API\AccountController;
use App\Http\Controllers\API\MembersController;
use App\Http\Controllers\API\NationProfitabilityController;
use App\Http\Controllers\API\RaidFinderController;
use App\Http\Controllers\API\TradePriceController;
use App\Http\Controllers\API\WarSimulatorController as ApiWarSimulatorController;
use App\Http\Middleware\AdminMiddleware;
use App\Http\Middleware\DiscordVerifiedMiddleware;
use App\Http\Middleware\EnsureMfaConfigured;
use App\Http\Middleware\EnsureUserIsVerified;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', EnsureMfaConfigured::class])->prefix('v1')->group(function () {
    Route::get('/nations/{nationId}/profitability', [NationProfitabilityController::class, 'show'])
        ->middleware('throttle:profitability-calculations');
});

Route::prefix('v1')->middleware(['auth:sanctum', EnsureUserIsVerified::class, DiscordVerifiedMiddleware::class, EnsureMfaConfigured::class])->group(function () {
    Route::get('/user', function (Request $request) {
        return $request->user();
    });

    Route::get('/accounts', [AccountController::class, 'getUserAccounts']);
    Route::post('/accounts/{account}/deposit-request', [AccountController::class, 'createDepositRequest']);
    Route::get('/defense/raid-finder/{nation_id?}', [RaidFinderController::class, 'show'])
        ->middleware('throttle:raid-finder')
        ->name('api.raid-finder.show');
    Route::get('/members', [MembersController::class, 'index'])->middleware([AdminMiddleware::class, 'can:view-members']);
    Route::get('/trade-prices/average-24h', [TradePriceController::class, 'average24h']);

    Route::prefix('simulators')->group(function () {
        Route::get('/war/defaults', [ApiWarSimulatorController::class, 'defaults']);
        Route::get('/nations/{nationId}', [ApiWarSimulatorController::class, 'nation']);
        Route::get('/wars/{warId}', [ApiWarSimulatorController::class, 'war']);
        Route::post('/run', [ApiWarSimulatorController::class, 'run'])->middleware('throttle:war-simulations');
    });
});
