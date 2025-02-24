<?php

use App\Http\Controllers\AccountsController;
use App\Http\Controllers\Admin\AccountController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\GrantController;
use App\Http\Controllers\CityGrantController;
use App\Http\Controllers\VerificationController;
use App\Http\Middleware\AdminMiddleware;
use App\Http\Middleware\EnsureUserIsVerified;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('home');
})->name("home");

Route::middleware(['auth'])->group(function () {
    // Verification
    Route::get('/verify/{code}', [VerificationController::class, 'verify'])->name('verify');
    Route::get('/notverified', [VerificationController::class, 'notVerified'])->name(
        'not_verified'
    );
    Route::post('/resend-verification', [VerificationController::class, 'resendVerification'])
        ->name('verification.resend');
});

Route::middleware(['auth', EnsureUserIsVerified::class,])->group(function () {
    // Account Routes
    Route::get("/accounts", [AccountsController::class, 'index'])->name("accounts");
    Route::post('accounts/transfer', [AccountsController::class, 'transfer'])
        ->name('accounts.transfer');

    Route::get("/accounts/create", [AccountsController::class, "createView"])->name("accounts.create");
    Route::post("/accounts/create", [AccountsController::class, "create"])->name("accounts.create.post");

    Route::post("/accounts/delete", [AccountsController::class, "delete"])->name("accounts.delete.post");

    Route::get("/accounts/{accounts}", [AccountsController::class, 'viewAccount'])->name("accounts.view");

    // City grants
    Route::get("/grants/city", [CityGrantController::class, 'index'])->name("grants.city");
    Route::post("/grants/city", [CityGrantController::class, 'request'])->name(
        "grants.city.request"
    );
});

Route::middleware(['auth', EnsureUserIsVerified::class, AdminMiddleware::class,])
    ->prefix("admin")
    ->group(function () {
        // Base routes
        Route::get("/", [DashboardController::class, 'dashboard'])->name("admin.dashboard");

        // Accounts
        Route::get("/accounts", [AccountController::class, 'dashboard'])->name("admin.accounts.dashboard");
        Route::get("/accounts/{accounts}", [AccountController::class, 'view'])->name("admin.accounts.view");
        Route::post('/accounts/{account}/adjust', [AccountController::class, 'adjustBalance'])->name(
            'admin.accounts.adjust'
        );

        // City Grants
        Route::get("/grants/city", [GrantController::class, 'cityGrants'])->name(
            "admin.grants.city"
        );

        Route::post("/grants/city/approve/{CityGrantRequest}", [GrantController::class, 'approveCityGrant'])->name(
            "admin.grants.city.approve"
        );

        Route::post("/grants/city/deny/{CityGrantRequest}", [GrantController::class, 'denyCityGrant'])->name(
            "admin.grants.city.deny"
        );
    });
