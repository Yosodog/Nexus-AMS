<?php

use App\Http\Controllers\Admin\AuditController as AdminAuditController;
use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\AuditRuleController;
use App\Http\Controllers\Admin\CityBuildAuditController;
use Illuminate\Support\Facades\Route;

// Audits
Route::middleware('can:view-audits')->group(function () {
    Route::get('/audits', [AdminAuditController::class, 'index'])->name('admin.audits.index');
    Route::get('/audits/city-builds', [CityBuildAuditController::class, 'index'])
        ->name('admin.audits.city-builds.index');
    Route::get('/audits/rules', [AuditRuleController::class, 'index'])->name('admin.audits.rules.index');
    Route::get('/audits/rules/{auditRule}/violations', [AdminAuditController::class, 'violations'])
        ->name('admin.audits.rules.violations');
});
Route::middleware('can:manage-audits')->group(function () {
    Route::get('/audits/rules/create', [AuditRuleController::class, 'create'])->name('admin.audits.rules.create');
    Route::post('/audits/rules', [AuditRuleController::class, 'store'])->name('admin.audits.rules.store');
    Route::post('/audits/rules/preview', [AuditRuleController::class, 'preview'])->name('admin.audits.rules.preview');
    Route::get('/audits/rules/{auditRule}/edit', [AuditRuleController::class, 'edit'])->name('admin.audits.rules.edit');
    Route::put('/audits/rules/{auditRule}', [AuditRuleController::class, 'update'])->name('admin.audits.rules.update');
    Route::delete('/audits/rules/{auditRule}', [AuditRuleController::class, 'destroy'])->name('admin.audits.rules.destroy');
    Route::post('/audits/run', [AdminAuditController::class, 'run'])->name('admin.audits.run');
    Route::post('/audits/notify', [AdminAuditController::class, 'notify'])->name('admin.audits.notify');
    Route::post('/audits/city-builds/recommendations', [CityBuildAuditController::class, 'regenerateAll'])
        ->name('admin.audits.city-builds.recommendations.regenerate-all');
    Route::post('/audits/city-builds/{nation}/recommendation', [CityBuildAuditController::class, 'regenerate'])
        ->name('admin.audits.city-builds.recommendations.regenerate');
    Route::patch('/audits/results/{auditResult}/remediation', [AdminAuditController::class, 'updateRemediation'])
        ->name('admin.audits.results.remediation');
});
Route::get('/audit-logs', [AuditLogController::class, 'index'])->name('admin.audit-logs.index');
