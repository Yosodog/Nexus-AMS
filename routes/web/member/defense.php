<?php

use App\Http\Controllers\BlockadeReliefController;
use App\Http\Controllers\CounterFinderController;
use App\Http\Controllers\IntelReportController;
use App\Http\Controllers\RaidFinderController;
use App\Http\Controllers\RaidingLeaderboardController;
use App\Http\Controllers\RebuildingController;
use App\Http\Controllers\WarAidController;
use App\Http\Controllers\WarSimulatorController;
use App\Http\Controllers\WarStatsController;
use App\Http\Middleware\BlockWhenPWDown;
use Illuminate\Support\Facades\Route;

/***** Defense Routes *****/
Route::prefix('defense')->middleware(['auth'])->group(function () {
    // Counters
    Route::get('/counters/{nation?}', [CounterFinderController::class, 'index'])
        ->name('defense.counters');

    // War aid
    Route::get('/waraid', [WarAidController::class, 'index'])->name('defense.war-aid');
    Route::post('/waraid', [WarAidController::class, 'store'])->name('defense.war-aid.store')
        ->middleware(BlockWhenPWDown::class);
    Route::get('/rebuilding', [RebuildingController::class, 'index'])->name('defense.rebuilding');
    Route::post('/rebuilding', [RebuildingController::class, 'store'])->name('defense.rebuilding.store')
        ->middleware(BlockWhenPWDown::class);

    Route::get('/raid-finder', [RaidFinderController::class, 'index'])->name(
        'defense.raid-finder'
    )->middleware(BlockWhenPWDown::class);

    Route::get('/war-stats', WarStatsController::class)->name('defense.war-stats');
    Route::get('/simulators', WarSimulatorController::class)->name('defense.simulators');
    Route::get('/raid-leaderboard', RaidingLeaderboardController::class)->name('defense.raid-leaderboard');
    Route::get('/intel', [IntelReportController::class, 'index'])->name('defense.intel');
    Route::post('/intel', [IntelReportController::class, 'store'])->name('defense.intel.store');
    Route::get('/blockade-relief', [BlockadeReliefController::class, 'index'])->name('defense.blockade-relief');
    Route::post('/blockade-relief', [BlockadeReliefController::class, 'store'])->name('defense.blockade-relief.store');
    Route::post('/blockade-relief/{blockadeReliefRequest}/claim', [BlockadeReliefController::class, 'claim'])
        ->name('defense.blockade-relief.claim');
    Route::post('/blockade-relief/{blockadeReliefRequest}/cancel', [BlockadeReliefController::class, 'cancel'])
        ->name('defense.blockade-relief.cancel');
});
// Counters
