<?php

use App\Http\Controllers\Admin\BeigeAlertController;
use App\Http\Controllers\Admin\MilcomPageController;
use App\Http\Controllers\Admin\RaidController;
use App\Http\Controllers\Admin\RebuildingController as AdminRebuildingControllerAlias;
use App\Http\Controllers\Admin\SpyCampaignController;
use App\Http\Controllers\Admin\WarAidController as AdminWarAidControllerAlias;
use App\Http\Controllers\Admin\WarController as AdminWarController;
use App\Http\Controllers\Admin\WarCounterController as AdminWarCounterController;
use App\Http\Controllers\Admin\WarPlanController as AdminWarPlanController;
use App\Http\Controllers\Admin\WarRoomController;
use App\Http\Middleware\BlockWhenPWDown;
use App\Http\Middleware\RequireMilcomV2;
use Illuminate\Support\Facades\Route;

// War
Route::get('/defense/wars', [AdminWarController::class, 'index'])->name('admin.wars');

// War Room & Campaign management
Route::prefix('milcom')->middleware(['can:manage-war-room', RequireMilcomV2::class])->group(function () {
    Route::get('/', [MilcomPageController::class, 'dashboard'])->name('admin.milcom.dashboard');
    Route::get('/plans', [MilcomPageController::class, 'plans'])->name('admin.milcom.plans');
    Route::get('/plans/create', [MilcomPageController::class, 'createPlan'])
        ->name('admin.milcom.plans.create');
    Route::get('/plans/{operation}/export.csv', [MilcomPageController::class, 'exportPlanCsv'])
        ->name('admin.milcom.plans.export');
    Route::get('/plans/{operation}', [MilcomPageController::class, 'showPlan'])
        ->name('admin.milcom.plans.show');
    Route::get('/counters', [MilcomPageController::class, 'counters'])
        ->name('admin.milcom.counters');
    Route::get('/archive', [MilcomPageController::class, 'archive'])
        ->name('admin.milcom.archive');
    Route::get('/archive/legacy/{type}/{id}', [MilcomPageController::class, 'legacyHistory'])
        ->name('admin.milcom.archive.legacy');
    Route::get('/archive/{operation}', [MilcomPageController::class, 'operationHistory'])
        ->name('admin.milcom.archive.show');
    Route::get('/settings', [MilcomPageController::class, 'settings'])
        ->name('admin.milcom.settings');
});

Route::get('/war-room', [WarRoomController::class, 'index'])->name('admin.war-room');
Route::post('/war-room/discord-channel', [WarRoomController::class, 'updateDiscordChannel'])
    ->name('admin.war-room.discord-channel');
Route::post('/war-room/default-forum', [WarRoomController::class, 'updateDefaultWarRoomForum'])
    ->name('admin.war-room.default-forum');
Route::post('/war-room/defense-role', [WarRoomController::class, 'updateWarRoomDefenseRole'])
    ->name('admin.war-room.defense-role');
Route::post('/war-room/creation', [WarRoomController::class, 'updateWarRoomCreation'])
    ->name('admin.war-room.creation');

Route::post('/war-plans', [AdminWarPlanController::class, 'store'])->name('admin.war-plans.store');
Route::get('/war-plans/{plan}', [AdminWarPlanController::class, 'show'])->name('admin.war-plans.show');
Route::put('/war-plans/{plan}', [AdminWarPlanController::class, 'update'])->name('admin.war-plans.update');
Route::post('/war-plans/{plan}/activate', [AdminWarPlanController::class, 'activate'])->name('admin.war-plans.activate');
Route::post('/war-plans/{plan}/archive', [AdminWarPlanController::class, 'archive'])->name('admin.war-plans.archive');
Route::post('/war-plans/{plan}/recompute', [AdminWarPlanController::class, 'recompute'])->name('admin.war-plans.recompute');
Route::post('/war-plans/{plan}/auto-assign', [AdminWarPlanController::class, 'autoAssign'])->name('admin.war-plans.auto-assign');
Route::post('/war-plans/{plan}/publish', [AdminWarPlanController::class, 'publish'])->name('admin.war-plans.publish');
Route::get('/war-plans/{plan}/export', [AdminWarPlanController::class, 'export'])->name('admin.war-plans.export');
Route::post('/war-plans/{plan}/import', [AdminWarPlanController::class, 'import'])->name('admin.war-plans.import');
Route::get('/war-plans/{plan}/targets/export-csv', [AdminWarPlanController::class, 'exportTargetsCsv'])->name('admin.war-plans.targets.export-csv');
Route::get('/war-plans/{plan}/assignments/export-csv', [AdminWarPlanController::class, 'exportAssignmentsCsv'])->name('admin.war-plans.assignments.export-csv');
Route::post('/war-plans/{plan}/targets/{target}/war-type', [AdminWarPlanController::class, 'updateTargetWarType'])->name('admin.war-plans.targets.update-war-type');
Route::post('/war-plans/{plan}/alliances', [AdminWarPlanController::class, 'addAlliance'])->name('admin.war-plans.alliances.store');
Route::delete('/war-plans/{plan}/alliances/{alliance}', [AdminWarPlanController::class, 'removeAlliance'])->name('admin.war-plans.alliances.destroy');
Route::post('/war-plans/{plan}/targets', [AdminWarPlanController::class, 'addTarget'])->name('admin.war-plans.targets.store');
Route::delete('/war-plans/{plan}/targets/{target}', [AdminWarPlanController::class, 'removeTarget'])->name('admin.war-plans.targets.destroy');
Route::post('/war-plans/{plan}/assignments/manual', [AdminWarPlanController::class, 'storeManualAssignment'])->name('admin.war-plans.assignments.manual');
Route::delete('/war-plans/{plan}/assignments/{assignment}', [AdminWarPlanController::class, 'removeAssignment'])->name('admin.war-plans.assignments.destroy');

// Spy Campaigns
Route::get('/spy-campaigns', [SpyCampaignController::class, 'index'])->name('admin.spy-campaigns.index');
Route::post('/spy-campaigns', [SpyCampaignController::class, 'store'])->name('admin.spy-campaigns.store');
Route::get('/spy-campaigns/{spyCampaign}', [SpyCampaignController::class, 'show'])->name('admin.spy-campaigns.show');
Route::put('/spy-campaigns/{spyCampaign}', [SpyCampaignController::class, 'update'])->name('admin.spy-campaigns.update');
Route::post('/spy-campaigns/{spyCampaign}/alliances', [SpyCampaignController::class, 'addAlliance'])->name('admin.spy-campaigns.alliances.store');
Route::delete('/spy-campaigns/{spyCampaign}/alliances/{spyCampaignAlliance}', [SpyCampaignController::class, 'removeAlliance'])->name('admin.spy-campaigns.alliances.destroy');
Route::post('/spy-campaigns/{spyCampaign}/rounds', [SpyCampaignController::class, 'addRound'])->name('admin.spy-campaigns.rounds.store');
Route::post('/spy-campaign-rounds/{spyRound}/generate', [SpyCampaignController::class, 'generate'])->name('admin.spy-campaigns.rounds.generate');
Route::post('/spy-campaign-rounds/{spyRound}/message', [SpyCampaignController::class, 'sendMessages'])->name('admin.spy-campaigns.rounds.message');
Route::get('/spy-campaign-rounds/{spyRound}', [SpyCampaignController::class, 'round'])->name('admin.spy-campaigns.rounds.show');

Route::post('/war-counters', [AdminWarCounterController::class, 'store'])->name('admin.war-counters.store');
Route::get('/war-counters/{counter}', [AdminWarCounterController::class, 'show'])->name('admin.war-counters.show');
Route::post('/war-counters/{counter}/update', [AdminWarCounterController::class, 'update'])->name('admin.war-counters.update');
Route::post('/war-counters/{counter}/auto-pick', [AdminWarCounterController::class, 'autoPick'])->name('admin.war-counters.auto-pick');
Route::post('/war-counters/{counter}/assignments/manual', [AdminWarCounterController::class, 'storeManualAssignment'])->name('admin.war-counters.assignments.manual');
Route::post('/war-counters/{counter}/assignments/{assignment}/assign', [AdminWarCounterController::class, 'assign'])->name('admin.war-counters.assignments.assign');
Route::post('/war-counters/{counter}/assignments/{assignment}/unassign', [AdminWarCounterController::class, 'unassign'])->name('admin.war-counters.assignments.unassign');
Route::delete('/war-counters/{counter}/assignments/{assignment}', [AdminWarCounterController::class, 'removeAssignment'])->name('admin.war-counters.assignments.destroy');
Route::post('/war-counters/{counter}/finalize', [AdminWarCounterController::class, 'finalize'])->name('admin.war-counters.finalize');
Route::post('/war-counters/{counter}/reimbursements', [AdminWarCounterController::class, 'storeReimbursement'])->name('admin.war-counters.reimbursements.store');
Route::post('/war-counters/{counter}/archive', [AdminWarCounterController::class, 'archive'])->name('admin.war-counters.archive');

// War Aid
Route::get('/defense/waraid', [AdminWarAidControllerAlias::class, 'index'])->name(
    'admin.war-aid'
);
Route::patch(
    '/defense/waraid/{WarAidRequest}/approve',
    [AdminWarAidControllerAlias::class, 'approve']
)->name('admin.war-aid.approve')->middleware(BlockWhenPWDown::class);
Route::patch(
    '/defense/waraid/{WarAidRequest}/deny',
    [AdminWarAidControllerAlias::class, 'deny']
)->name('admin.war-aid.deny')->middleware(BlockWhenPWDown::class);
Route::post('/defense/waraid/toggle', [AdminWarAidControllerAlias::class, 'toggle'])->name(
    'admin.war-aid.toggle'
);
Route::get('/defense/rebuilding', [AdminRebuildingControllerAlias::class, 'index'])->name('admin.rebuilding.index');
Route::post('/defense/rebuilding/toggle', [AdminRebuildingControllerAlias::class, 'toggle'])->name(
    'admin.rebuilding.toggle'
);
Route::post('/defense/rebuilding/tiers', [AdminRebuildingControllerAlias::class, 'storeTier'])->name(
    'admin.rebuilding.tiers.store'
);
Route::put('/defense/rebuilding/tiers/{tier}', [AdminRebuildingControllerAlias::class, 'updateTier'])->name(
    'admin.rebuilding.tiers.update'
);
Route::delete('/defense/rebuilding/tiers/{tier}', [AdminRebuildingControllerAlias::class, 'destroyTier'])->name(
    'admin.rebuilding.tiers.destroy'
);
Route::patch(
    '/defense/rebuilding/{RebuildingRequest}/approve',
    [AdminRebuildingControllerAlias::class, 'approve']
)->name('admin.rebuilding.approve')->middleware(BlockWhenPWDown::class);
Route::patch(
    '/defense/rebuilding/{RebuildingRequest}/deny',
    [AdminRebuildingControllerAlias::class, 'deny']
)->name('admin.rebuilding.deny')->middleware(BlockWhenPWDown::class);
Route::post('/defense/rebuilding/ineligible', [AdminRebuildingControllerAlias::class, 'markIneligible'])->name(
    'admin.rebuilding.ineligible.store'
);
Route::delete(
    '/defense/rebuilding/ineligible/{ineligibilityId}',
    [AdminRebuildingControllerAlias::class, 'clearIneligible']
)->name('admin.rebuilding.ineligible.destroy');
Route::post('/defense/rebuilding/refresh-estimates', [AdminRebuildingControllerAlias::class, 'refreshEstimates'])->name(
    'admin.rebuilding.refresh-estimates'
);
Route::post('/defense/rebuilding/reset', [AdminRebuildingControllerAlias::class, 'resetCycle'])->name(
    'admin.rebuilding.reset'
);

Route::get('/defense/raids', [RaidController::class, 'index'])->name('admin.raids.index');
Route::post('/defense/raids/no-raid', [RaidController::class, 'storeNoRaid'])->name(
    'admin.raids.no-raid.store'
);
Route::delete('/defense/raids/no-raid/{id}', [RaidController::class, 'destroyNoRaid'])->name(
    'admin.raids.no-raid.destroy'
);
Route::post('/defense/raids/top-cap', [RaidController::class, 'updateTopCap'])->name(
    'admin.raids.top-cap.update'
);
Route::get('/defense/beige-alerts', [BeigeAlertController::class, 'index'])->name('admin.beige-alerts.index');
Route::post('/defense/beige-alerts/settings', [BeigeAlertController::class, 'updateSettings'])->name(
    'admin.beige-alerts.settings'
);
Route::post('/defense/beige-alerts/alliances', [BeigeAlertController::class, 'storeAlliance'])->name(
    'admin.beige-alerts.alliances.store'
);
Route::delete(
    '/defense/beige-alerts/alliances/{beigeAlertAlliance}',
    [BeigeAlertController::class, 'destroyAlliance']
)->name('admin.beige-alerts.alliances.destroy');
