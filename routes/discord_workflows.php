<?php

use App\Http\Controllers\API\Discord\AlertSubscriptionController as DiscordAlertSubscriptionController;
use App\Http\Controllers\API\Discord\AuditController as DiscordAuditController;
use App\Http\Controllers\API\Discord\BlockadeReliefController as DiscordBlockadeReliefController;
use App\Http\Controllers\API\Discord\OperationsController;
use App\Http\Controllers\API\Discord\StaffController;
use App\Http\Controllers\API\Discord\WorkflowController;
use App\Http\Middleware\EnsureDiscordInteractionIdempotency;
use App\Http\Middleware\ResolveDiscordActor;
use App\Http\Middleware\ValidateDiscordBotAPI;
use Illuminate\Support\Facades\Route;

Route::middleware([ValidateDiscordBotAPI::class, ResolveDiscordActor::class])->group(function (): void {
    Route::prefix('me')->group(function (): void {
        Route::get('/requests', [WorkflowController::class, 'requests']);
        Route::get('/grants', [WorkflowController::class, 'grants']);
        Route::get('/loans', [WorkflowController::class, 'loans']);
        Route::get('/war-aid', [WorkflowController::class, 'warAid']);
        Route::get('/rebuilding/preview', [WorkflowController::class, 'rebuildingPreview']);
        Route::get('/raids', [OperationsController::class, 'raids']);
        Route::get('/wars', [OperationsController::class, 'wars']);
        Route::get('/wars/counter', [OperationsController::class, 'warCounter']);
        Route::get('/wars/{war}/simulation', [OperationsController::class, 'warSimulation']);
        Route::get('/war-assignments', [OperationsController::class, 'warAssignments']);
        Route::get('/spy-assignments', [OperationsController::class, 'spyAssignments']);
        Route::get('/applications', [OperationsController::class, 'applications']);
        Route::get('/audits', [DiscordAuditController::class, 'index']);
        Route::get('/alerts', [DiscordAlertSubscriptionController::class, 'index']);
        Route::get('/blockade-relief', [DiscordBlockadeReliefController::class, 'index']);
        Route::get('/blockade-relief/available', [DiscordBlockadeReliefController::class, 'available']);

        Route::middleware(EnsureDiscordInteractionIdempotency::class)->group(function (): void {
            Route::post('/grant-applications/preview', [WorkflowController::class, 'previewGrant']);
            Route::post('/grant-applications/confirm', [WorkflowController::class, 'confirmGrant']);
            Route::post('/city-grant-requests/preview', [WorkflowController::class, 'previewCityGrant']);
            Route::post('/city-grant-requests/confirm', [WorkflowController::class, 'confirmCityGrant']);
            Route::post('/loan-applications/preview', [WorkflowController::class, 'previewLoanApplication']);
            Route::post('/loan-applications/confirm', [WorkflowController::class, 'confirmLoanApplication']);
            Route::post('/loan-payments/preview', [WorkflowController::class, 'previewLoanPayment']);
            Route::post('/loan-payments/confirm', [WorkflowController::class, 'confirmLoanPayment']);
            Route::post('/war-aid/draft', [WorkflowController::class, 'draftWarAid']);
            Route::post('/war-aid/review', [WorkflowController::class, 'reviewWarAid']);
            Route::post('/war-aid/confirm', [WorkflowController::class, 'confirmWarAid']);
            Route::post('/rebuilding/confirm', [WorkflowController::class, 'rebuildingConfirm']);
            Route::post('/war-assignments/{type}/{id}/response', [OperationsController::class, 'respondToWarAssignment']);
            Route::post('/audits/{auditResult}/acknowledge', [DiscordAuditController::class, 'acknowledge']);
            Route::post('/audits/{auditResult}/snooze', [DiscordAuditController::class, 'snooze']);
            Route::post('/alerts', [DiscordAlertSubscriptionController::class, 'store']);
            Route::patch('/alerts/{alertSubscription}/status', [DiscordAlertSubscriptionController::class, 'updateStatus']);
            Route::post('/alerts/{alertSubscription}/test', [DiscordAlertSubscriptionController::class, 'test']);
            Route::delete('/alerts/{alertSubscription}', [DiscordAlertSubscriptionController::class, 'destroy']);
            Route::post('/blockade-relief', [DiscordBlockadeReliefController::class, 'store']);
            Route::post('/blockade-relief/{blockadeReliefRequest}/claim', [DiscordBlockadeReliefController::class, 'claim']);
            Route::post('/blockade-relief/{blockadeReliefRequest}/cancel', [DiscordBlockadeReliefController::class, 'cancel']);
        });
    });

    Route::prefix('staff')->group(function (): void {
        Route::get('/requests', [StaffController::class, 'requests']);
        Route::get('/applications', [StaffController::class, 'applications']);
        Route::get('/applications/{application}', [StaffController::class, 'application']);

        Route::middleware(EnsureDiscordInteractionIdempotency::class)->group(function (): void {
            Route::post('/applications/{application}/approve', [StaffController::class, 'approveApplication']);
            Route::post('/applications/{application}/deny', [StaffController::class, 'denyApplication']);
        });
    });
});
