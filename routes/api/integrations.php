<?php

use App\Http\Controllers\API\SubController;
use App\Http\Middleware\ValidateNexusAPI;
use Illuminate\Support\Facades\Route;

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
