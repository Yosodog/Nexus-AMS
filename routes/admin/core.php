<?php

use App\Http\Controllers\Admin\CommandPaletteController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\MemberTransferController as AdminMemberTransferController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\StaffWorkQueueController;
use App\Http\Controllers\Admin\UserController as AdminUserController;
use Illuminate\Support\Facades\Route;

// Base routes
Route::get('/', [DashboardController::class, 'dashboard'])->name('admin.dashboard');
Route::get('/command-palette/search', [CommandPaletteController::class, 'search'])
    ->middleware('throttle:60,1')
    ->name('admin.command-palette.search');
Route::get('/work-queue', [StaffWorkQueueController::class, 'index'])
    ->name('admin.work-queue.index');
Route::post('/work-queue/saved-views', [StaffWorkQueueController::class, 'storeSavedView'])
    ->name('admin.work-queue.saved-views.store');
Route::delete('/work-queue/saved-views/{savedView}', [StaffWorkQueueController::class, 'destroySavedView'])
    ->whereUuid('savedView')
    ->name('admin.work-queue.saved-views.destroy');

// Users
Route::get('/users', [AdminUserController::class, 'index'])->name('admin.users.index');
Route::post('/users/mfa-requirements', [AdminUserController::class, 'updateMfaRequirements'])->name('admin.users.mfa-requirements');
Route::get('/user/{user}/edit', [AdminUserController::class, 'edit'])->name('admin.users.edit');
Route::put('/user/{user}', [AdminUserController::class, 'update'])->name('admin.users.update');
Route::post('/user/{user}/discord/unlink', [AdminUserController::class, 'unlinkDiscord'])->name(
    'admin.users.discord.unlink'
);

Route::get('/member-transfers/{memberTransfer}', [AdminMemberTransferController::class, 'show'])
    ->name('admin.member-transfers.show');
Route::post('/member-transfers/{memberTransfer}/cancel', [AdminMemberTransferController::class, 'cancel'])
    ->name('admin.member-transfers.cancel');

// Roles
Route::get('/roles', [RoleController::class, 'index'])->name('admin.roles.index');
Route::get('/roles/create', [RoleController::class, 'create'])->name('admin.roles.create');
Route::post('/roles', [RoleController::class, 'store'])->name('admin.roles.store');
Route::get('/roles/{role}/edit', [RoleController::class, 'edit'])->name('admin.roles.edit');
Route::put('/roles/{role}', [RoleController::class, 'update'])->name('admin.roles.update');
Route::delete('/roles/{role}', [RoleController::class, 'destroy'])->name('admin.roles.destroy');
