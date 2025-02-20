<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\API\AccountController;

Route::prefix('v1')->middleware("auth:sanctum")->group(function() {
    Route::get('/user', function (Request $request) {
        return $request->user();
    });

    Route::get('/accounts', [AccountController::class, 'getUserAccounts']);
    Route::post('/accounts/{account}/deposit-request', [AccountController::class, 'createDepositRequest']);
});
