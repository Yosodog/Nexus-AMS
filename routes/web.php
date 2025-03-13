<?php

use App\Http\Controllers\AccountsController;
use App\Http\Controllers\Admin\AccountController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\GrantController;
use App\Http\Controllers\Admin\LoansController;
use App\Http\Controllers\CityGrantController;
use App\Http\Controllers\LoansController as UserLoansController;
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

Route::middleware(['auth', EnsureUserIsVerified::class,])->group(callback: function () {
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

    // Loans
    Route::get("/loans", [UserLoansController::class, 'index'])->name("loans.index");
    Route::post('/loans/apply', [UserLoansController::class, 'apply'])->name('loans.apply');
    Route::post('/loans/repay', [UserLoansController::class, 'repay'])->name('loans.repay');
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
        Route::post('/grants/city/{city_grant}/update', [GrantController::class, 'updateCityGrant'])
            ->name("admin.grants.city.update");

        Route::post('/grants/city/create', [GrantController::class, 'createCityGrant'])->name(
            "admin.grants.city.create"
        );

        Route::post("/grants/city/approve/{CityGrantRequest}", [GrantController::class, 'approveCityGrant'])->name(
            "admin.grants.city.approve"
        );

        Route::post("/grants/city/deny/{CityGrantRequest}", [GrantController::class, 'denyCityGrant'])->name(
            "admin.grants.city.deny"
        );

        // Loans
        Route::get("/loans", [LoansController::class, 'index'])->name("admin.loans");
        Route::post("/loans/approve/{Loans}", [LoansController::class, 'approve'])->name(
            "admin.loans.approve"
        );
        Route::post("/loans/deny/{Loans}", [LoansController::class, 'deny'])->name(
            "admin.loans.deny"
        );

        Route::post("/loans/edit/{Loans}", [LoansController::class, 'edit'])->name(
            "admin.loans.edit"
        );
    });
