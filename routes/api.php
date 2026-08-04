<?php

use App\Http\Controllers\Admin\WarPlanController;
use App\Http\Controllers\API\AccountController;
use App\Http\Controllers\API\Discord\ApplicationController as DiscordApplicationController;
use App\Http\Controllers\API\Discord\FinanceController as DiscordFinanceController;
use App\Http\Controllers\API\Discord\MilcomObjectiveController as DiscordMilcomObjectiveController;
use App\Http\Controllers\API\Discord\OffshoreController as DiscordOffshoreController;
use App\Http\Controllers\API\Discord\WarCounterController as DiscordWarCounterController;
use App\Http\Controllers\API\DiscordQueueController;
use App\Http\Controllers\API\DiscordVerificationController;
use App\Http\Controllers\API\IntelReportController as ApiIntelReportController;
use App\Http\Controllers\API\MembersController;
use App\Http\Controllers\API\Milcom\ObjectiveController as MilcomObjectiveController;
use App\Http\Controllers\API\Milcom\OperationController as MilcomOperationController;
use App\Http\Controllers\API\Milcom\ReadController as MilcomReadController;
use App\Http\Controllers\API\Milcom\SettingsController as MilcomSettingsController;
use App\Http\Controllers\API\NationProfitabilityController;
use App\Http\Controllers\API\RaidFinderController;
use App\Http\Controllers\API\SubController;
use App\Http\Controllers\API\TradePriceController;
use App\Http\Controllers\API\WarSimulatorController as ApiWarSimulatorController;
use App\Http\Middleware\AdminMiddleware;
use App\Http\Middleware\DiscordVerifiedMiddleware;
use App\Http\Middleware\EnsureDiscordInteractionCommand;
use App\Http\Middleware\EnsureDiscordInteractionIdempotency;
use App\Http\Middleware\EnsureMfaConfigured;
use App\Http\Middleware\EnsureUserIsVerified;
use App\Http\Middleware\RequireMilcomV2;
use App\Http\Middleware\ResolveDiscordActor;
use App\Http\Middleware\ValidateDiscordBotAPI;
use App\Http\Middleware\ValidateNexusAPI;
use App\Http\Middleware\VerifyDiscordInteraction;
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
    Route::get('/defense/raid-finder/{nation_id?}', [RaidFinderController::class, 'show']);
    Route::get('/members', [MembersController::class, 'index'])->middleware([AdminMiddleware::class, 'can:view-members']);
    Route::get('/trade-prices/average-24h', [TradePriceController::class, 'average24h']);

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
    Route::post('/queue/claim', [DiscordQueueController::class, 'claim']);
    Route::post('/queue/{command}/lease', [DiscordQueueController::class, 'lease']);
    Route::patch('/queue/{command}/checkpoint', [DiscordQueueController::class, 'checkpoint']);
    Route::post('/queue/{command}/status', [DiscordQueueController::class, 'update']);
    Route::post('/applications', [DiscordApplicationController::class, 'store']);
    Route::post('/applications/attach-channel', [DiscordApplicationController::class, 'attachChannel']);
    Route::post('/applications/messages', [DiscordApplicationController::class, 'storeMessage']);
    Route::post('/applications/approve', [DiscordApplicationController::class, 'approve'])
        ->middleware([
            VerifyDiscordInteraction::class,
            ResolveDiscordActor::class,
            EnsureDiscordInteractionCommand::class.':approve',
        ]);
    Route::post('/applications/deny', [DiscordApplicationController::class, 'deny'])
        ->middleware([
            VerifyDiscordInteraction::class,
            ResolveDiscordActor::class,
            EnsureDiscordInteractionCommand::class.':deny',
        ]);
    Route::post('/war-counters/attach-channel', [DiscordWarCounterController::class, 'attachChannel'])
        ->middleware([
            VerifyDiscordInteraction::class,
            EnsureDiscordInteractionCommand::class.':war-counters.attach-channel',
        ]);
    Route::post('/war-counters/archive', [DiscordWarCounterController::class, 'archive'])
        ->middleware([
            VerifyDiscordInteraction::class,
            ResolveDiscordActor::class,
            EnsureDiscordInteractionCommand::class.':archivecounter',
            EnsureDiscordInteractionIdempotency::class,
        ]);
    Route::get('/war-counters/{counter}', [DiscordWarCounterController::class, 'show']);
    Route::get('/milcom/objectives/{objective}', [DiscordMilcomObjectiveController::class, 'show'])
        ->middleware(RequireMilcomV2::class);
    Route::post('/milcom/objectives/attach-room', [DiscordMilcomObjectiveController::class, 'attachRoom'])
        ->middleware([
            RequireMilcomV2::class,
            VerifyDiscordInteraction::class,
            EnsureDiscordInteractionCommand::class.':milcom.objectives.attach-room',
        ]);
    Route::post('/offshores/sweep-primary', [DiscordOffshoreController::class, 'sweepPrimary'])
        ->middleware([
            VerifyDiscordInteraction::class,
            ResolveDiscordActor::class,
            EnsureDiscordInteractionCommand::class.':sweepbank',
            EnsureDiscordInteractionIdempotency::class,
        ]);
    Route::post('/intel', [ApiIntelReportController::class, 'store']);

    Route::prefix('me')->middleware([VerifyDiscordInteraction::class, ResolveDiscordActor::class])->group(function () {
        Route::get('/accounts', [DiscordFinanceController::class, 'accounts']);
        Route::get('/accounts/{account}/transactions', [DiscordFinanceController::class, 'transactions']);
        Route::get('/withdrawals/{intent}', [DiscordFinanceController::class, 'reviewWithdrawal']);

        Route::middleware(EnsureDiscordInteractionIdempotency::class)->group(function () {
            Route::post('/accounts/{account}/deposit-requests', [DiscordFinanceController::class, 'createDepositRequest']);
            Route::post('/withdrawals/drafts', [DiscordFinanceController::class, 'createWithdrawalDraft']);
            Route::post('/withdrawals/{intent}/confirm', [DiscordFinanceController::class, 'confirmWithdrawal']);
            Route::post('/withdrawals/{intent}/cancel', [DiscordFinanceController::class, 'cancelWithdrawal']);
        });
    });
});

Route::middleware(['auth:sanctum', EnsureUserIsVerified::class, DiscordVerifiedMiddleware::class, EnsureMfaConfigured::class, AdminMiddleware::class])
    ->prefix('v1/war-plans')
    ->group(function () {
        Route::get('/{plan}/targets', [WarPlanController::class, 'targetsData'])->name('api.admin.war-plans.targets');
        Route::get('/{plan}/targets/{target}/candidates', [WarPlanController::class, 'targetCandidatesData'])->name('api.admin.war-plans.target-candidates');
        Route::get('/{plan}/assignments', [WarPlanController::class, 'assignmentsData'])->name('api.admin.war-plans.assignments');
        Route::get('/{plan}/friendlies', [WarPlanController::class, 'friendliesData'])->name('api.admin.war-plans.friendlies');
    });

Route::middleware([
    'auth:sanctum',
    EnsureUserIsVerified::class,
    DiscordVerifiedMiddleware::class,
    EnsureMfaConfigured::class,
    AdminMiddleware::class,
    'can:manage-war-room',
    RequireMilcomV2::class,
])->prefix('v1/milcom')->group(function () {
    Route::get('/dashboard', [MilcomReadController::class, 'dashboard']);
    Route::get('/alliances', [MilcomReadController::class, 'alliances']);
    Route::get('/operations', [MilcomReadController::class, 'operations']);
    Route::get('/operations/{operation}', [MilcomReadController::class, 'operation']);
    Route::get('/operations/{operation}/objectives', [MilcomReadController::class, 'objectives']);
    Route::get('/operations/{operation}/assignments', [MilcomReadController::class, 'assignments']);
    Route::get('/objectives/{objective}', [MilcomReadController::class, 'objective']);
    Route::get('/incidents', [MilcomReadController::class, 'incidents']);
    Route::get('/incidents/{incident}', [MilcomReadController::class, 'incident']);
    Route::get('/recommendation-runs/{run}', [MilcomReadController::class, 'recommendationRun'])
        ->name('api.milcom.recommendation-runs.show');
    Route::get('/events', [MilcomReadController::class, 'events']);
    Route::get('/archive/legacy/{type}/{id}', [MilcomReadController::class, 'legacy']);

    Route::post('/operations', [MilcomOperationController::class, 'store']);
    Route::put('/operations/{operation}/scope', [MilcomOperationController::class, 'commitScope']);
    Route::post('/operations/{operation}/recommendations', [MilcomOperationController::class, 'recommend']);
    Route::post('/operations/{operation}/objectives/approve', [MilcomOperationController::class, 'batchApprove']);
    Route::post('/operations/{operation}/objectives/approve-eligible', [MilcomOperationController::class, 'approveEligible']);
    Route::post('/operations/{operation}/objectives/approve-reviewable', [MilcomOperationController::class, 'approveReviewable']);
    Route::post('/operations/{operation}/dispatch', [MilcomOperationController::class, 'batchDispatch']);
    Route::post('/operations/{operation}/dispatch-ready', [MilcomOperationController::class, 'dispatchReady']);
    Route::post('/operations/{operation}/deliver-in-game', [MilcomOperationController::class, 'deliverAssignments']);
    Route::post('/operations/{operation}/activate', [MilcomOperationController::class, 'activate']);
    Route::post('/operations/{operation}/complete', [MilcomOperationController::class, 'complete']);
    Route::post('/operations/{operation}/archive', [MilcomOperationController::class, 'archive']);
    Route::post('/operations/{operation}/clone', [MilcomOperationController::class, 'clone']);

    Route::patch('/objectives/{objective}', [MilcomObjectiveController::class, 'update']);
    Route::post('/objectives/{objective}/approve', [MilcomObjectiveController::class, 'approve']);
    Route::post('/objectives/{objective}/dispatch', [MilcomObjectiveController::class, 'dispatchObjective']);
    Route::put('/objectives/{objective}/assignments', [MilcomObjectiveController::class, 'applyAlternative']);
    Route::post('/objectives/{objective}/assignments/manual', [MilcomObjectiveController::class, 'setManualAssignment']);
    Route::delete(
        '/objectives/{objective}/assignments/{assignment}',
        [MilcomObjectiveController::class, 'releaseAssignment']
    );
    Route::post('/objectives/{objective}/cancel', [MilcomObjectiveController::class, 'cancel']);
    Route::post('/objectives/{objective}/dispatch/retry', [MilcomObjectiveController::class, 'retry']);
    Route::post('/settings', [MilcomSettingsController::class, 'update']);
});
