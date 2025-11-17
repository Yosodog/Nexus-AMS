<?php

use App\Http\Controllers\API\AccountController;
use App\Http\Controllers\API\DiscordVerificationController;
use App\Http\Controllers\API\RaidFinderController;
use App\Http\Controllers\API\SubController;
use App\Http\Middleware\ValidateDiscordBotAPI;
use App\Http\Middleware\ValidateNexusAPI;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware('auth:sanctum')->group(function () {
    Route::get('/user', function (Request $request) {
        return $request->user();
    });

    Route::get('/accounts', [AccountController::class, 'getUserAccounts']);
    Route::post('/accounts/{account}/deposit-request', [AccountController::class, 'createDepositRequest']);
    Route::get('/defense/raid-finder/{nation_id?}', [RaidFinderController::class, 'show']);
});

Route::prefix('v1/subs')->middleware(ValidateNexusAPI::class)->group(function () {
    Route::post('nation/update', [SubController::class, 'updateNation']);
    Route::post('nation/create', [SubController::class, 'createNation']);
    Route::post('nation/delete', [SubController::class, 'deleteNation']);

    Route::post('alliance/create', [SubController::class, 'createAlliance']);
    Route::post('alliance/update', [SubController::class, 'updateAlliance']);
    Route::post('alliance/delete', [SubController::class, 'deleteAlliance']);

    Route::post('city/create', [SubController::class, 'createCity']);
    Route::post('city/update', [SubController::class, 'updateCity']);
    Route::post('city/delete', [SubController::class, 'deleteCity']);

    Route::post('war/create', [SubController::class, 'createWar']);
    Route::post('war/update', [SubController::class, 'updateWar']);
    Route::post('war/delete', [SubController::class, 'deleteWar']);

    Route::post('warattack/create', [SubController::class, 'createWarAttack']);

    Route::post('account/create', [SubController::class, 'createAccount']);
    Route::post('account/update', [SubController::class, 'updateAccount']);
    Route::post('account/delete', [SubController::class, 'deleteAccount']);
});

Route::prefix('v1/discord')->middleware(ValidateDiscordBotAPI::class)->group(function () {
    Route::post('/verify', [DiscordVerificationController::class, 'verify']);
});
