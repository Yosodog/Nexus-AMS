<?php

use App\Http\Controllers\API\AccountController;
use App\Http\Controllers\API\SubUpdateController;
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
    Route::post('nation/update', [SubUpdateController::class, 'updateNation']);
    Route::post('nation/create', [SubUpdateController::class, 'createNation']);
    Route::post('nation/delete', [SubUpdateController::class, 'deleteNation']);
});


