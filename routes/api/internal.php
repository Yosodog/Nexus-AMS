<?php

declare(strict_types=1);

use App\Http\Controllers\API\Internal\BuildMetadataController;
use App\Http\Controllers\API\Internal\ReadinessController;
use App\Http\Controllers\API\Internal\RuntimeHealthController;
use App\Http\Middleware\ValidateNexusAPI;
use Illuminate\Support\Facades\Route;

Route::prefix('internal/v1')->middleware(ValidateNexusAPI::class)->group(function (): void {
    Route::get('build', BuildMetadataController::class)->name('api.internal.build');
    Route::get('readiness', ReadinessController::class)->name('api.internal.readiness');
    Route::get('health', RuntimeHealthController::class)->name('api.internal.health');
});
