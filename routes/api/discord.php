<?php

use App\Http\Controllers\API\Discord\AlertRendererManifestController as DiscordAlertRendererManifestController;
use App\Http\Controllers\API\Discord\ApplicationController as DiscordApplicationController;
use App\Http\Controllers\API\Discord\DirectoryController as DiscordDirectoryController;
use App\Http\Controllers\API\Discord\FinanceController as DiscordFinanceController;
use App\Http\Controllers\API\Discord\MemberContextController as DiscordMemberContextController;
use App\Http\Controllers\API\Discord\MemberProfileSyncController as DiscordMemberProfileSyncController;
use App\Http\Controllers\API\Discord\MilcomObjectiveController as DiscordMilcomObjectiveController;
use App\Http\Controllers\API\Discord\OffshoreController as DiscordOffshoreController;
use App\Http\Controllers\API\Discord\OffshoreSweepIntentController as DiscordOffshoreSweepIntentController;
use App\Http\Controllers\API\Discord\OperationsWorkItemController as DiscordOperationsWorkItemController;
use App\Http\Controllers\API\Discord\StatusController as DiscordStatusController;
use App\Http\Controllers\API\Discord\WarCounterController as DiscordWarCounterController;
use App\Http\Controllers\API\DiscordQueueController;
use App\Http\Controllers\API\DiscordVerificationController;
use App\Http\Controllers\API\IntelReportController as ApiIntelReportController;
use App\Http\Middleware\EnsureDiscordInteractionCommand;
use App\Http\Middleware\EnsureDiscordInteractionIdempotency;
use App\Http\Middleware\RequireMilcomV2;
use App\Http\Middleware\ResolveDiscordActor;
use App\Http\Middleware\ValidateDiscordBotAPI;
use App\Http\Middleware\VerifyDiscordInteraction;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/discord')->middleware(ValidateDiscordBotAPI::class)->group(function () {
    Route::post('/verify', [DiscordVerificationController::class, 'verify']);
    Route::post('/link/preview', [DiscordVerificationController::class, 'preview'])
        ->middleware([
            VerifyDiscordInteraction::class,
            EnsureDiscordInteractionCommand::class.':verify',
        ]);
    Route::post('/link/confirm', [DiscordVerificationController::class, 'confirm'])
        ->middleware([
            VerifyDiscordInteraction::class,
            EnsureDiscordInteractionCommand::class.':verify',
            EnsureDiscordInteractionIdempotency::class,
        ]);
    Route::get('/queue', [DiscordQueueController::class, 'index'])
        ->middleware(VerifyDiscordInteraction::class.':queue.legacy-fetch,legacy-unsigned');
    Route::post('/queue/claim', [DiscordQueueController::class, 'claim'])
        ->middleware(VerifyDiscordInteraction::class.':queue.claim,legacy-unsigned');
    Route::post('/queue/{command}/lease', [DiscordQueueController::class, 'lease'])
        ->middleware(VerifyDiscordInteraction::class.':queue.lease,legacy-unsigned');
    Route::patch('/queue/{command}/checkpoint', [DiscordQueueController::class, 'checkpoint'])
        ->middleware(VerifyDiscordInteraction::class.':queue.checkpoint,legacy-unsigned');
    Route::post('/queue/{command}/status', [DiscordQueueController::class, 'update'])
        ->middleware(VerifyDiscordInteraction::class.':queue.acknowledge,legacy-unsigned');
    Route::get('/alerts/manifest', DiscordAlertRendererManifestController::class)
        ->middleware([
            VerifyDiscordInteraction::class,
            EnsureDiscordInteractionCommand::class.':alerts.manifest',
        ]);
    Route::get('/status', DiscordStatusController::class)
        ->middleware([
            VerifyDiscordInteraction::class,
            ResolveDiscordActor::class,
            EnsureDiscordInteractionCommand::class.':nexus.status',
        ]);
    Route::get('/context', [DiscordMemberContextController::class, 'context'])
        ->middleware(VerifyDiscordInteraction::class);
    Route::get('/me/summary', [DiscordMemberContextController::class, 'summary'])
        ->middleware([
            VerifyDiscordInteraction::class,
            EnsureDiscordInteractionCommand::class.':me',
        ]);
    Route::post('/me/profile-sync/preview', [DiscordMemberProfileSyncController::class, 'preview'])
        ->middleware([
            VerifyDiscordInteraction::class,
            ResolveDiscordActor::class,
            EnsureDiscordInteractionCommand::class.':me',
        ]);
    Route::post('/me/profile-sync/confirm', [DiscordMemberProfileSyncController::class, 'confirm'])
        ->middleware([
            VerifyDiscordInteraction::class,
            ResolveDiscordActor::class,
            EnsureDiscordInteractionCommand::class.':me',
            EnsureDiscordInteractionIdempotency::class,
        ]);
    Route::post('/applications/preview', [DiscordApplicationController::class, 'preview'])
        ->middleware([
            VerifyDiscordInteraction::class,
            EnsureDiscordInteractionCommand::class.':apply',
        ]);
    Route::post('/applications/confirm', [DiscordApplicationController::class, 'confirm'])
        ->middleware([
            VerifyDiscordInteraction::class,
            EnsureDiscordInteractionCommand::class.':apply',
            EnsureDiscordInteractionIdempotency::class,
        ]);
    Route::post('/applications', [DiscordApplicationController::class, 'store']);
    Route::post('/applications/attach-channel', [DiscordApplicationController::class, 'attachChannel']);
    Route::post('/applications/messages', [DiscordApplicationController::class, 'storeMessage']);
    Route::post('/applications/approve', [DiscordApplicationController::class, 'approve'])
        ->middleware([
            VerifyDiscordInteraction::class,
            ResolveDiscordActor::class,
            EnsureDiscordInteractionCommand::class.':approve,applications.approve',
        ]);
    Route::post('/applications/deny', [DiscordApplicationController::class, 'deny'])
        ->middleware([
            VerifyDiscordInteraction::class,
            ResolveDiscordActor::class,
            EnsureDiscordInteractionCommand::class.':deny,applications.deny',
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
            EnsureDiscordInteractionCommand::class.':archivecounter,war-counters.archive',
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
            EnsureDiscordInteractionCommand::class.':sweepbank,offshores.sweep-primary',
            EnsureDiscordInteractionIdempotency::class,
        ]);
    Route::post('/offshores/sweep-primary/preview', [DiscordOffshoreSweepIntentController::class, 'preview'])
        ->middleware([
            VerifyDiscordInteraction::class,
            ResolveDiscordActor::class,
            EnsureDiscordInteractionCommand::class.':sweepbank',
        ]);
    Route::post('/offshores/sweep-primary/confirm', [DiscordOffshoreSweepIntentController::class, 'confirm'])
        ->middleware([
            VerifyDiscordInteraction::class,
            ResolveDiscordActor::class,
            EnsureDiscordInteractionCommand::class.':sweepbank',
            EnsureDiscordInteractionIdempotency::class,
        ]);
    Route::post('/intel', [ApiIntelReportController::class, 'store']);

    Route::prefix('directory')->middleware([VerifyDiscordInteraction::class, ResolveDiscordActor::class])->group(function () {
        Route::get('/discord-users/{discordUserId}', [DiscordDirectoryController::class, 'discordUser'])
            ->middleware(EnsureDiscordInteractionCommand::class.':who')
            ->whereNumber('discordUserId');
        Route::get('/nations', [DiscordDirectoryController::class, 'nations'])
            ->middleware(EnsureDiscordInteractionCommand::class.':nation');
        Route::get('/nations/{nation}', [DiscordDirectoryController::class, 'nation'])
            ->middleware(EnsureDiscordInteractionCommand::class.':nation')
            ->whereNumber('nation');
        Route::get('/alliances', [DiscordDirectoryController::class, 'alliances'])
            ->middleware(EnsureDiscordInteractionCommand::class.':alliance');
        Route::get('/alliances/{alliance}', [DiscordDirectoryController::class, 'alliance'])
            ->middleware(EnsureDiscordInteractionCommand::class.':alliance')
            ->whereNumber('alliance');
    });

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

    Route::prefix('staff')
        ->middleware([VerifyDiscordInteraction::class, ResolveDiscordActor::class])
        ->group(function (): void {
            Route::get('/work-items', [DiscordOperationsWorkItemController::class, 'index'])
                ->name('api.discord.staff.work-items.index');
            Route::get('/work-items/{type}/{id}', [DiscordOperationsWorkItemController::class, 'show'])
                ->name('api.discord.staff.work-items.show');
            Route::post('/work-items/{type}/{id}/claim', [DiscordOperationsWorkItemController::class, 'claim'])
                ->middleware([
                    EnsureDiscordInteractionCommand::class.':work.claim',
                    EnsureDiscordInteractionIdempotency::class,
                ])
                ->name('api.discord.staff.work-items.claim');
            Route::post('/work-items/{type}/{id}/release', [DiscordOperationsWorkItemController::class, 'release'])
                ->middleware([
                    EnsureDiscordInteractionCommand::class.':work.release',
                    EnsureDiscordInteractionIdempotency::class,
                ])
                ->name('api.discord.staff.work-items.release');
        });
});
