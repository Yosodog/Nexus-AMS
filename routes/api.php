<?php

use App\Http\Controllers\API\AccountController;
use App\Http\Controllers\API\SubController;
use App\Http\Middleware\ValidateNexusAPI;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware("auth:sanctum")->group(function () {
    Route::get('/user', function (Request $request) {
        return $request->user();
    });

    Route::get('/accounts', [AccountController::class, 'getUserAccounts']);
    Route::post('/accounts/{account}/deposit-request', [AccountController::class, 'createDepositRequest']);
});

Route::prefix('v1/subs')->middleware(ValidateNexusAPI::class)->group(function () {
    Route::post('nation/update', [SubController::class, 'updateNation']);
    Route::post('nation/create', [SubController::class, 'createNation']);
    Route::post('nation/delete', [SubController::class, 'deleteNation']);

    Route::post('alliance/create', [SubController::class, 'createAlliance']);
    Route::post('alliance/update', [SubController::class, 'updateAlliance']);
    Route::post('alliance/delete', [SubController::class, 'deleteAlliance']);
});


