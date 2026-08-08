<?php

use App\Http\Controllers\AuditController;
use Illuminate\Support\Facades\Route;

// Audits
Route::get('/audit', [AuditController::class, 'index'])->name('audit.index');
Route::post('/audit/results/{auditResult}/acknowledge', [AuditController::class, 'acknowledge'])
    ->name('audit.results.acknowledge');
Route::post('/audit/results/{auditResult}/snooze', [AuditController::class, 'snooze'])
    ->name('audit.results.snooze');
Route::post('/audit/recommendation/regenerate', [AuditController::class, 'regenerate'])
    ->middleware('throttle:build-recommendation-regeneration')
    ->name('audit.recommendation.regenerate');
