<?php

use App\Http\Controllers\AccountsController;
use App\Http\Controllers\Admin\AccountController;
use App\Http\Controllers\Admin\CityGrantController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\GrantController as AdminGrantController;
use App\Http\Controllers\Admin\LoansController;
use App\Http\Controllers\Admin\MembersController as AdminMembersController;
use App\Http\Controllers\Admin\TaxesController as AdminTaxesController;
use App\Http\Controllers\Admin\WarAidController as AdminWarAidControllerAlias;
use App\Http\Controllers\Admin\WarController as AdminWarController;
use App\Http\Controllers\CityGrantController as UserCityGrantController;
use App\Http\Controllers\GrantController as UserGrantController;
use App\Http\Controllers\LoansController as UserLoansController;
use App\Http\Controllers\UserController;
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
    // User settings
    Route::get('/user/settings', [UserController::class, 'settings'])->name('user.settings');
    Route::post('/user/settings/update', [UserController::class, 'updateSettings'])->name(
        'user.settings.update'
    );
    // Account Routes
    Route::get("/accounts", [AccountsController::class, 'index'])->name("accounts");
    Route::post('accounts/transfer', [AccountsController::class, 'transfer'])
        ->name('accounts.transfer');

    Route::get("/accounts/create", [AccountsController::class, "createView"])->name("accounts.create");
    Route::post("/accounts/create", [AccountsController::class, "create"])->name("accounts.create.post");

    Route::post("/accounts/delete", [AccountsController::class, "delete"])->name("accounts.delete.post");

    Route::get("/accounts/{accounts}", [AccountsController::class, 'viewAccount'])->name("accounts.view");

    // Loan
    Route::get("/loans", [UserLoansController::class, 'index'])->name("loans.index");
    Route::post('/loans/apply', [UserLoansController::class, 'apply'])->name('loans.apply');
    Route::post('/loans/repay', [UserLoansController::class, 'repay'])->name('loans.repay');

    /***** Defense Routes *****/
    Route::prefix('defense')->middleware(['auth'])->group(function () {
        // Counters
        Route::get('/counters/{nation?}', [\App\Http\Controllers\CounterFinderController::class, 'index'])
            ->name('defense.counters');

        // War aid
        Route::get('/waraid', [\App\Http\Controllers\WarAidController::class, 'index'])->name('defense.war-aid');
        Route::post('/waraid', [\App\Http\Controllers\WarAidController::class, 'store'])->name('defense.war-aid.store');

        Route::get('/raid-finder', [\App\Http\Controllers\RaidFinderController::class, 'index'])->name('defense.raid-finder');

    });
    // Counters


    // Grants
    Route::prefix('grants')->middleware(['auth'])->group(function () {
        // City grants
        Route::get("/city", [UserCityGrantController::class, 'index'])->name("grants.city");
        Route::post("/city", [UserCityGrantController::class, 'request'])->name(
            "grants.city.request"
        );

        Route::get('{grant:slug}', [UserGrantController::class, 'show'])->name('grants.show_grants');
        Route::post('{grant:slug}/apply', [UserGrantController::class, 'apply'])->name('grants.apply');
    });
});

Route::middleware(['auth', EnsureUserIsVerified::class, AdminMiddleware::class,])
    ->prefix("admin")
    ->group(function () {
        // Base routes
        Route::get("/", [DashboardController::class, 'dashboard'])->name("admin.dashboard");

        // Account
        Route::get("/accounts", [AccountController::class, 'dashboard'])->name("admin.accounts.dashboard");
        Route::get("/accounts/{accounts}", [AccountController::class, 'view'])->name("admin.accounts.view");
        Route::post('/accounts/{account}/adjust', [AccountController::class, 'adjustBalance'])->name(
            'admin.accounts.adjust'
        );

        // City Grants
        Route::get("/grants/city", [CityGrantController::class, 'cityGrants'])->name(
            "admin.grants.city"
        );
        Route::post('/grants/city/{city_grant}/update', [CityGrantController::class, 'updateCityGrant'])
            ->name("admin.grants.city.update");

        Route::post('/grants/city/create', [CityGrantController::class, 'createCityGrant'])->name(
            "admin.grants.city.create"
        );

        Route::post("/grants/city/approve/{CityGrantRequest}", [CityGrantController::class, 'approveCityGrant'])->name(
            "admin.grants.city.approve"
        );

        Route::post("/grants/city/deny/{CityGrantRequest}", [CityGrantController::class, 'denyCityGrant'])->name(
            "admin.grants.city.deny"
        );

        // Grants
        Route::get("/grants", [AdminGrantController::class, 'grants'])->name("admin.grants");
        Route::post("/grants/create", [AdminGrantController::class, 'createGrant'])->name("admin.grants.create");
        Route::post("/grants/{grant}/update", [AdminGrantController::class, 'updateGrant'])->name("admin.grants.update");
        Route::post("/grants/{grant}/toggle", [AdminGrantController::class, 'toggleGrant'])->name("admin.grants.toggle");

        Route::post("/grants/approve/{application}", [AdminGrantController::class, 'approveApplication'])->name("admin.grants.approve");
        Route::post("/grants/deny/{application}", [AdminGrantController::class, 'denyApplication'])->name("admin.grants.deny");

        // Loan
        Route::get("/loans", [LoansController::class, 'index'])->name("admin.loans");
        Route::post("/loans/{Loan}/approve", [LoansController::class, 'approve'])->name(
            "admin.loans.approve"
        );
        Route::post("/loans/{Loan}/deny", [LoansController::class, 'deny'])->name(
            "admin.loans.deny"
        );
        Route::get("/loans/{Loan}/view", [LoansController::class, 'view'])->name(
            "admin.loans.view"
        );
        Route::post('/loans/{Loan}/update', [LoansController::class, 'update'])->name(
            'admin.loans.update'
        );

        Route::post('/loans/{Loan}/mark-paid', [LoansController::class, 'markAsPaid'])->name(
            'admin.loans.markPaid'
        );

        // Taxes
        Route::get('/taxes', [AdminTaxesController::class, 'index'])->name('admin.taxes');

        // Members
        Route::get('/members', [AdminMembersController::class, 'index'])->name('admin.members');
        Route::get('/members/{Nation}', [AdminMembersController::class, 'show'])->name('admin.members.show');

        // War
        Route::get('/defense/wars', [AdminWarController::class, 'index'])->name('admin.wars');

        // War Aid
        Route::get('/defense/waraid', [AdminWarAidControllerAlias::class, 'index'])->name(
            'admin.war-aid'
        );
        Route::patch(
            '/defense/waraid/{WarAidRequest}/approve',
            [AdminWarAidControllerAlias::class, 'approve']
        )->name('admin.war-aid.approve');
        Route::patch(
            '/defense/waraid/{WarAidRequest}/deny',
            [AdminWarAidControllerAlias::class, 'deny']
        )->name('admin.war-aid.deny');
        Route::post('/defense/waraid/toggle', [AdminWarAidControllerAlias::class, 'toggle'])->name(
            'admin.war-aid.toggle'
        );
    });
