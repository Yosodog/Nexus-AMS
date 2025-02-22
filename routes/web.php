<?php

use App\Http\Middleware\AdminMiddleware;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AccountsController;
use App\Http\Middleware\EnsureUserIsVerified;

Route::get('/', function () {
    return view('home');
})->name("home");

Route::middleware(['auth'])->group(function() {
    // Verification
    Route::get('/verify/{code}', [\App\Http\Controllers\VerificationController::class, 'verify'])->name('verify');
});

Route::middleware(['auth',
                   EnsureUserIsVerified::class])
    ->group(function () {
    // Account Routes
    Route::get("/accounts", [AccountsController::class, 'index'])->name("accounts");
    Route::post('accounts/transfer', [AccountsController::class, 'transfer'])
        ->name('accounts.transfer');

    Route::get("/accounts/create", [AccountsController::class, "createView"])->name("accounts.create");
    Route::post("/accounts/create", [AccountsController::class, "create"])->name("accounts.create.post");

    Route::post("/accounts/delete", [AccountsController::class, "delete"])->name("accounts.delete.post");

    Route::get("/accounts/{accounts}", [AccountsController::class, 'viewAccount'])->name("accounts.view");

});

Route::middleware(['auth',
                   EnsureUserIsVerified::class,
                   AdminMiddleware::class])
    ->prefix("admin")
    ->group(function() {
    // Base routes
    Route::get("/", [\App\Http\Controllers\Admin\DashboardController::class, 'dashboard'])->name("admin.dashboard");

    // Accounts
    Route::get("/accounts", [\App\Http\Controllers\Admin\AccountController::class, 'dashboard'])->name("admin.accounts.dashboard");
    Route::get("/accounts/{accounts}", [\App\Http\Controllers\Admin\AccountController::class, 'view'])->name("admin.accounts.view");
    Route::post('/accounts/{account}/adjust', [\App\Http\Controllers\Admin\AccountController::class, 'adjustBalance'])->name('admin.accounts.adjust');

});
