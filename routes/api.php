<?php

use App\Http\Controllers\Admin\WarPlanController;
use App\Http\Controllers\API\AccountController;
use App\Http\Controllers\API\Discord\ApplicationController as DiscordApplicationController;
use App\Http\Controllers\API\DiscordQueueController;
use App\Http\Controllers\API\DiscordVerificationController;
use App\Http\Controllers\API\IntelReportController as ApiIntelReportController;
use App\Http\Controllers\API\MembersController;
use App\Http\Controllers\API\RaidFinderController;
use App\Http\Controllers\API\SubController;
use App\Http\Controllers\API\WarSimulatorController as ApiWarSimulatorController;
use App\Http\Middleware\AdminMiddleware;
use App\Http\Middleware\DiscordVerifiedMiddleware;
use App\Http\Middleware\EnsureUserIsVerified;
use App\Http\Middleware\ValidateDiscordBotAPI;
use App\Http\Middleware\ValidateNexusAPI;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware(['auth:sanctum', EnsureUserIsVerified::class, DiscordVerifiedMiddleware::class])->group(function () {
    Route::get('/user', function (Request $request) {
        return $request->user();
    });

    Route::get('/accounts', [AccountController::class, 'getUserAccounts']);
    Route::post('/accounts/{account}/deposit-request', [AccountController::class, 'createDepositRequest']);
    Route::get('/defense/raid-finder/{nation_id?}', [RaidFinderController::class, 'show']);
    Route::get('/members', [MembersController::class, 'index'])->middleware([AdminMiddleware::class, 'can:view-members']);

    Route::prefix('simulators')->group(function () {
        Route::get('/war/defaults', [ApiWarSimulatorController::class, 'defaults']);
        Route::get('/nations/{nationId}', [ApiWarSimulatorController::class, 'nation']);
        Route::get('/wars/{warId}', [ApiWarSimulatorController::class, 'war']);
        Route::post('/run', [ApiWarSimulatorController::class, 'run'])->middleware('throttle:war-simulations');
    });
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
    Route::get('/queue', [DiscordQueueController::class, 'index']);
    Route::post('/queue/{command}/status', [DiscordQueueController::class, 'update']);
    Route::post('/applications', [DiscordApplicationController::class, 'store']);
    Route::post('/applications/attach-channel', [DiscordApplicationController::class, 'attachChannel']);
    Route::post('/applications/messages', [DiscordApplicationController::class, 'storeMessage']);
    Route::post('/applications/approve', [DiscordApplicationController::class, 'approve']);
    Route::post('/applications/deny', [DiscordApplicationController::class, 'deny']);
    Route::post('/intel', [ApiIntelReportController::class, 'store']);
});

Route::middleware(['auth:sanctum', EnsureUserIsVerified::class, DiscordVerifiedMiddleware::class, AdminMiddleware::class])
    ->prefix('v1/war-plans')
    ->group(function () {
        Route::get('/{plan}/targets', [WarPlanController::class, 'targetsData'])->name('api.admin.war-plans.targets');
        Route::get('/{plan}/assignments', [WarPlanController::class, 'assignmentsData'])->name('api.admin.war-plans.assignments');
        Route::get('/{plan}/friendlies', [WarPlanController::class, 'friendliesData'])->name('api.admin.war-plans.friendlies');
    });
