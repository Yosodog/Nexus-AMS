<?php

use App\Http\Controllers\Admin\MMRController;
use Illuminate\Support\Facades\Route;

Route::prefix('mmr')->group(function () {
    Route::get('/', [MMRController::class, 'index'])->name('admin.mmr.index');
    Route::post('/store', [MMRController::class, 'store'])->name('admin.mmr.store');
    Route::delete('/destroy', [MMRController::class, 'destroy'])->name('admin.mmr.destroy');
    Route::post('/update-all', [MMRController::class, 'updateAll'])->name('admin.mmr.updateAll');
    Route::post('/bulk-edit-resources', [MMRController::class, 'bulkEditResources'])->name('admin.mmr.bulk-edit-resources');
    Route::post('/update-weights', [MMRController::class, 'updateWeights'])->name('admin.mmr.weights.update');
    Route::post('/update-mmr-assistant-settings', [MMRController::class, 'updateAssistantSettings'])->name('admin.mmr.assistant.update');
});
