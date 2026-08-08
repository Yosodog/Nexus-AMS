<?php

use App\Http\Controllers\Admin\ApplicationController as AdminApplicationController;
use App\Http\Controllers\Admin\RecruitmentController;
use App\Http\Middleware\BlockWhenPWDown;
use Illuminate\Support\Facades\Route;

Route::get('/applications', [AdminApplicationController::class, 'index'])->name('admin.applications.index');
Route::post('/applications/settings', [AdminApplicationController::class, 'updateSettings'])->name(
    'admin.applications.settings'
);
Route::get('/applications/{application}', [AdminApplicationController::class, 'show'])->name(
    'admin.applications.show'
);
Route::post('/applications/{application}/cancel', [AdminApplicationController::class, 'cancel'])->name(
    'admin.applications.cancel'
);

Route::get('/recruitment', [RecruitmentController::class, 'index'])->name('admin.recruitment.index');
Route::post('/recruitment', [RecruitmentController::class, 'update'])->name('admin.recruitment.update');
Route::post('/recruitment/test', [RecruitmentController::class, 'sendTest'])->name(
    'admin.recruitment.test'
)->middleware(BlockWhenPWDown::class);
