<?php

use App\Http\Controllers\Admin\DataSyncSettingsController;
use App\Http\Controllers\Admin\DiscordSettingsController;
use App\Http\Controllers\Admin\FinancePolicySettingsController;
use App\Http\Controllers\Admin\PendingRequestRecoveryController;
use App\Http\Controllers\Admin\PublicSiteSettingsController;
use App\Http\Controllers\Admin\SecurityRetentionSettingsController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\SystemHealthController;
use App\Http\Middleware\BlockWhenPWDown;
use Illuminate\Support\Facades\Route;

Route::get('/settings', [SettingsController::class, 'index'])->name('admin.settings');
Route::get('/settings/public-site', [PublicSiteSettingsController::class, 'index'])->name(
    'admin.settings.public-site'
);
Route::get('/settings/discord', [DiscordSettingsController::class, 'index'])->name(
    'admin.settings.discord.index'
);
Route::get('/settings/finance-policy', [FinancePolicySettingsController::class, 'index'])->name(
    'admin.settings.finance-policy'
);
Route::get('/settings/security-retention', [SecurityRetentionSettingsController::class, 'index'])->name(
    'admin.settings.security-retention'
);
Route::get('/settings/data-sync', [DataSyncSettingsController::class, 'index'])->name(
    'admin.settings.data-sync'
);
Route::get('/settings/recovery', [PendingRequestRecoveryController::class, 'index'])->name(
    'admin.settings.recovery'
);
Route::get('/settings/system-health', SystemHealthController::class)->name(
    'admin.settings.system-health'
);
Route::post('/settings/sync/nations', [DataSyncSettingsController::class, 'runNation'])->name(
    'admin.settings.sync.run'
)->middleware(BlockWhenPWDown::class);
Route::post('/settings/sync/alliances', [DataSyncSettingsController::class, 'runAlliance'])->name(
    'admin.settings.sync.alliances'
)->middleware(BlockWhenPWDown::class);

Route::post('/settings/sync/wars', [DataSyncSettingsController::class, 'runWar'])->name(
    'admin.settings.sync.wars'
)->middleware(BlockWhenPWDown::class);
Route::post('/settings/sync/cancel', [DataSyncSettingsController::class, 'cancel'])->name(
    'admin.settings.sync.cancel'
);
Route::post('/settings/discord', [DiscordSettingsController::class, 'updateVerification'])->name(
    'admin.settings.discord'
);
Route::post('/settings/discord/departure', [DiscordSettingsController::class, 'updateDeparture'])->name(
    'admin.settings.discord.departure'
);
Route::post('/settings/discord/private-notifications', [DiscordSettingsController::class, 'updatePrivateNotifications'])
    ->name('admin.settings.discord.private-notifications');
Route::post('/settings/discord/city-tiers', [DiscordSettingsController::class, 'updateCityTiers'])
    ->name('admin.settings.discord.city-tiers');
Route::post('/settings/homepage', [PublicSiteSettingsController::class, 'updateHomepage'])->name(
    'admin.settings.homepage'
);
Route::post('/settings/seo', [PublicSiteSettingsController::class, 'updateSeo'])->name(
    'admin.settings.seo'
);
Route::post('/settings/favicon', [PublicSiteSettingsController::class, 'updateFavicon'])->name(
    'admin.settings.favicon'
);
Route::post('/settings/auto-withdraw', [FinancePolicySettingsController::class, 'updateAutoWithdraw'])->name(
    'admin.settings.auto-withdraw'
);
Route::post('/settings/backups', [SecurityRetentionSettingsController::class, 'updateBackups'])->name(
    'admin.settings.backups'
);
Route::post('/settings/loan-payments', [FinancePolicySettingsController::class, 'updateLoanPayments'])->name(
    'admin.settings.loan-payments'
);
Route::post('/settings/grants/approvals', [FinancePolicySettingsController::class, 'updateGrantApprovals'])->name(
    'admin.settings.grants.approvals'
);
Route::post('/settings/audit-retention', [SecurityRetentionSettingsController::class, 'updateAuditRetention'])->name(
    'admin.settings.audit-retention'
);
Route::post('/settings/account-inactivity-auto-disable', [SecurityRetentionSettingsController::class, 'updateUserInactivity'])->name(
    'admin.settings.account-inactivity-auto-disable'
);
Route::post('/settings/pending-requests/release-stale', [PendingRequestRecoveryController::class, 'store'])->name(
    'admin.settings.pending-requests.release-stale'
);
