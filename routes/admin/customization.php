<?php

use App\Http\Controllers\Admin\CustomizationController;
use App\Http\Controllers\Admin\CustomizationImageController;
use Illuminate\Support\Facades\Route;

Route::prefix('customization')
    ->middleware('can:manage-custom-pages')
    ->group(function () {
        Route::get('/', [CustomizationController::class, 'index'])->name('admin.customization.index');
        Route::get('/pages/{page}', [CustomizationController::class, 'edit'])->name('admin.customization.edit');
        Route::post('/pages/{page}/preview', [CustomizationController::class, 'preview'])
            ->middleware('throttle:custom-page-previews')
            ->name('admin.customization.preview');
        Route::post('/pages/{page}/draft', [CustomizationController::class, 'saveDraft'])->name('admin.customization.draft');
        Route::post('/pages/{page}/publish', [CustomizationController::class, 'publish'])->name('admin.customization.publish');
        Route::get('/pages/{page}/versions', [CustomizationController::class, 'versions'])->name('admin.customization.versions');
        Route::post('/pages/{page}/restore', [CustomizationController::class, 'restore'])->name('admin.customization.restore');

        Route::post('/images', [CustomizationImageController::class, 'store'])->name('admin.customization.images.store');
        Route::get('/images/{token}', [CustomizationImageController::class, 'show'])
            ->middleware('signed')
            ->name('admin.customization.images.show');
    });
