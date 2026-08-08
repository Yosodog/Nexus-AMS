<?php

use App\Http\Controllers\Admin\MemberInactivityExceptionController;
use App\Http\Controllers\Admin\MembersController as AdminMembersController;
use Illuminate\Support\Facades\Route;

// Members
Route::get('/members', [AdminMembersController::class, 'index'])->name('admin.members');
Route::get('/members/{Nation}', [AdminMembersController::class, 'show'])->name('admin.members.show');
Route::post('/members/{nation}/inactivity-exceptions', [MemberInactivityExceptionController::class, 'store'])
    ->name('admin.members.inactivity-exceptions.store');
Route::put('/members/{nation}/inactivity-exceptions/{memberInactivityException}', [MemberInactivityExceptionController::class, 'update'])
    ->scopeBindings()
    ->name('admin.members.inactivity-exceptions.update');
Route::delete('/members/{nation}/inactivity-exceptions/{memberInactivityException}', [MemberInactivityExceptionController::class, 'destroy'])
    ->scopeBindings()
    ->name('admin.members.inactivity-exceptions.destroy');
Route::post('/members/inactivity-settings', [AdminMembersController::class, 'updateInactivitySettings'])
    ->name('admin.members.inactivity-settings');
Route::post('/members/inactivity-check', [AdminMembersController::class, 'runInactivityCheck'])
    ->name('admin.members.inactivity-check');
