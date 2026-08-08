<?php

use App\Http\Controllers\Admin\WarPlanController;
use App\Http\Controllers\API\Milcom\EventController as MilcomEventController;
use App\Http\Controllers\API\Milcom\ObjectiveController as MilcomObjectiveController;
use App\Http\Controllers\API\Milcom\OperationController as MilcomOperationController;
use App\Http\Controllers\API\Milcom\ReadController as MilcomReadController;
use App\Http\Controllers\API\Milcom\SettingsController as MilcomSettingsController;
use App\Http\Middleware\AdminMiddleware;
use App\Http\Middleware\DiscordVerifiedMiddleware;
use App\Http\Middleware\EnsureMfaConfigured;
use App\Http\Middleware\EnsureUserIsVerified;
use App\Http\Middleware\RejectFederationHeldOperation;
use App\Http\Middleware\RequireMilcomV2;
use Illuminate\Support\Facades\Route;

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
    RejectFederationHeldOperation::class,
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
    Route::post('/events/{event}/dismiss', [MilcomEventController::class, 'dismiss'])
        ->name('api.milcom.events.dismiss');
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
