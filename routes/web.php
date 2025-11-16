<?php

use App\Http\Controllers\AccountsController;
use App\Http\Controllers\Admin\AccountController;
use App\Http\Controllers\Admin\AllianceFinanceController;
use App\Http\Controllers\Admin\CityController;
use App\Http\Controllers\Admin\CityGrantController;
use App\Http\Controllers\Admin\CustomizationController;
use App\Http\Controllers\Admin\CustomizationImageController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\GrantController as AdminGrantController;
use App\Http\Controllers\Admin\LoansController;
use App\Http\Controllers\Admin\MembersController as AdminMembersController;
use App\Http\Controllers\Admin\MMRController;
use App\Http\Controllers\Admin\OffshoreController;
use App\Http\Controllers\Admin\RaidController;
use App\Http\Controllers\Admin\RecruitmentController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\TaxesController as AdminTaxesController;
use App\Http\Controllers\Admin\UserController as AdminUserController;
use App\Http\Controllers\Admin\WarAidController as AdminWarAidControllerAlias;
use App\Http\Controllers\Admin\WarController as AdminWarController;
use App\Http\Controllers\Admin\WarCounterController as AdminWarCounterController;
use App\Http\Controllers\Admin\WarPlanController as AdminWarPlanController;
use App\Http\Controllers\Admin\WarRoomController;
use App\Http\Controllers\Admin\WithdrawalController;
use App\Http\Controllers\ApplyPageController;
use App\Http\Controllers\CityGrantController as UserCityGrantController;
use App\Http\Controllers\CounterFinderController;
use App\Http\Controllers\DirectDepositController;
use App\Http\Controllers\GrantController as UserGrantController;
use App\Http\Controllers\LoansController as UserLoansController;
use App\Http\Controllers\RaidFinderController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\VerificationController;
use App\Http\Controllers\WarAidController;
use App\Http\Middleware\AdminMiddleware;
use App\Http\Middleware\BlockWhenPWDown;
use App\Http\Middleware\EnsureUserIsVerified;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('home');
})->name('home');

Route::get('/apply', [ApplyPageController::class, 'show'])->name('apply.show');

Route::middleware(['auth'])->group(function () {
    // Verification
    Route::get('/verify/{code}', [VerificationController::class, 'verify'])->name('verify');
    Route::get('/notverified', [VerificationController::class, 'notVerified'])->name(
        'not_verified'
    );
    Route::post('/resend-verification', [VerificationController::class, 'resendVerification'])
        ->name('verification.resend');
});

Route::middleware(['auth', EnsureUserIsVerified::class])->group(callback: function () {
    // User settings
    Route::get('/user/settings', [UserController::class, 'settings'])->name('user.settings');
    Route::post('/user/settings/update', [UserController::class, 'updateSettings'])->name(
        'user.settings.update'
    );

    // User dashboard
    Route::get('/user/dashboard', [UserController::class, 'dashboard'])->name('user.dashboard');

    // Account Routes
    Route::get('/accounts', [AccountsController::class, 'index'])->name('accounts');
    Route::post('accounts/transfer', [AccountsController::class, 'transfer'])
        ->name('accounts.transfer')
        ->middleware(BlockWhenPWDown::class);

    Route::get('/accounts/create', [AccountsController::class, 'createView'])->name('accounts.create');
    Route::post('/accounts/create', [AccountsController::class, 'create'])->name('accounts.create.post');

    Route::post('/accounts/delete', [AccountsController::class, 'delete'])->name('accounts.delete.post');

    Route::get('/accounts/{accounts}', [AccountsController::class, 'viewAccount'])->name('accounts.view');

    // Direct Deposit
    Route::post('/direct-deposit/enroll', [DirectDepositController::class, 'enroll'])->name('dd.enroll')
        ->middleware(BlockWhenPWDown::class);
    Route::post('/direct-deposit/disenroll', [DirectDepositController::class, 'disenroll'])->name('dd.disenroll')
        ->middleware(BlockWhenPWDown::class);

    // MMR Assistant
    Route::post('/mmr-assistant/update', [DirectDepositController::class, 'updateMMRA'])
        ->name('mmra.update');

    // Loan
    Route::get('/loans', [UserLoansController::class, 'index'])->name('loans.index');
    Route::post('/loans/apply', [UserLoansController::class, 'apply'])->name('loans.apply')
        ->middleware(BlockWhenPWDown::class);
    Route::post('/loans/repay', [UserLoansController::class, 'repay'])->name('loans.repay')
        ->middleware(BlockWhenPWDown::class);

    /***** Defense Routes *****/
    Route::prefix('defense')->middleware(['auth'])->group(function () {
        // Counters
        Route::get('/counters/{nation?}', [CounterFinderController::class, 'index'])
            ->name('defense.counters');

        // War aid
        Route::get('/waraid', [WarAidController::class, 'index'])->name('defense.war-aid');
        Route::post('/waraid', [WarAidController::class, 'store'])->name('defense.war-aid.store')
            ->middleware(BlockWhenPWDown::class);

        Route::get('/raid-finder', [RaidFinderController::class, 'index'])->name(
            'defense.raid-finder'
        )->middleware(BlockWhenPWDown::class);
    });
    // Counters

    // Grants
    Route::prefix('grants')->middleware(['auth'])->group(function () {
        // City grants
        Route::get('/city', [UserCityGrantController::class, 'index'])->name('grants.city');
        Route::post('/city', [UserCityGrantController::class, 'request'])->name(
            'grants.city.request'
        )
            ->middleware(BlockWhenPWDown::class);

        Route::get('{grant:slug}', [UserGrantController::class, 'show'])->name('grants.show_grants');
        Route::post('{grant:slug}/apply', [UserGrantController::class, 'apply'])->name('grants.apply')
            ->middleware(BlockWhenPWDown::class);
    });
});

Route::middleware(['auth', EnsureUserIsVerified::class, AdminMiddleware::class])
    ->prefix('admin')
    ->group(function () {
        // Base routes
        Route::get('/', [DashboardController::class, 'dashboard'])->name('admin.dashboard');

        // Users
        Route::get('/users', [AdminUserController::class, 'index'])->name('admin.users.index');
        Route::get('/user/{user}/edit', [AdminUserController::class, 'edit'])->name('admin.users.edit');
        Route::put('/user/{user}', [AdminUserController::class, 'update'])->name('admin.users.update');

        // Roles
        Route::get('/roles', [RoleController::class, 'index'])->name('admin.roles.index');
        Route::get('/roles/create', [RoleController::class, 'create'])->name('admin.roles.create');
        Route::post('/roles', [RoleController::class, 'store'])->name('admin.roles.store');
        Route::get('/roles/{role}/edit', [RoleController::class, 'edit'])->name('admin.roles.edit');
        Route::put('/roles/{role}', [RoleController::class, 'update'])->name('admin.roles.update');
        Route::delete('/roles/{role}', [RoleController::class, 'destroy'])->name('admin.roles.destroy');

        // Account
        Route::get('/accounts', [AccountController::class, 'dashboard'])->name('admin.accounts.dashboard');
        Route::get('/accounts/{accounts}', [AccountController::class, 'view'])->name('admin.accounts.view');
        Route::post('/accounts/{account}/adjust', [AccountController::class, 'adjustBalance'])->name(
            'admin.accounts.adjust'
        );
        Route::post('/accounts/transactions/{transaction}/refund', [AccountController::class, 'refundTransaction'])
            ->name('admin.accounts.transactions.refund')
            ->middleware(BlockWhenPWDown::class);

        Route::get('/cities', [CityController::class, 'index'])->name('admin.cities.index');

        Route::get('/offshores', [OffshoreController::class, 'index'])->name('admin.offshores.index');
        Route::get('/offshores/create', [OffshoreController::class, 'create'])->name('admin.offshores.create');
        Route::post('/offshores', [OffshoreController::class, 'store'])
            ->name('admin.offshores.store')
            ->middleware(BlockWhenPWDown::class);
        Route::get('/offshores/{offshore}/edit', [OffshoreController::class, 'edit'])->name('admin.offshores.edit');
        Route::put('/offshores/{offshore}', [OffshoreController::class, 'update'])
            ->name('admin.offshores.update')
            ->middleware(BlockWhenPWDown::class);
        Route::delete('/offshores/{offshore}', [OffshoreController::class, 'destroy'])
            ->name('admin.offshores.destroy')
            ->middleware(BlockWhenPWDown::class);
        Route::post('/offshores/reorder', [OffshoreController::class, 'reorder'])
            ->name('admin.offshores.reorder')
            ->middleware(BlockWhenPWDown::class);
        Route::post('/offshores/main-bank/refresh', [OffshoreController::class, 'refreshMainBank'])
            ->name('admin.offshores.main-bank.refresh')
            ->middleware(BlockWhenPWDown::class);
        Route::post('/offshores/{offshore}/toggle', [OffshoreController::class, 'toggle'])
            ->name('admin.offshores.toggle')
            ->middleware(BlockWhenPWDown::class);
        Route::post('/offshores/{offshore}/refresh', [OffshoreController::class, 'refresh'])
            ->name('admin.offshores.refresh')
            ->middleware(BlockWhenPWDown::class);
        Route::post('/offshores/main-bank/refresh', [OffshoreController::class, 'refreshMainBank'])
            ->name('admin.offshores.main-bank.refresh')
            ->middleware(BlockWhenPWDown::class);
        Route::post('/offshores/{offshore}/sweep', [OffshoreController::class, 'sweepToOffshore'])
            ->name('admin.offshores.sweep')
            ->middleware(BlockWhenPWDown::class);
        Route::post('/offshores/transfer', [OffshoreController::class, 'transfer'])
            ->name('admin.offshores.transfer')
            ->middleware(BlockWhenPWDown::class);

        Route::post('/admin/direct-deposit/settings', [AccountController::class, 'saveDirectDepositSettings'])
            ->name('admin.dd.settings');

        Route::post('/admin/direct-deposit/brackets/create', [AccountController::class, 'createDirectDepositBracket'])
            ->name('admin.dd.brackets.create');

        Route::post('/admin/direct-deposit/brackets/update', [AccountController::class, 'updateDirectDepositBrackets'])
            ->name('admin.dd.brackets.update');

        Route::post('/admin/direct-deposit/brackets/delete', [AccountController::class, 'deleteDirectDepositBrackets'])
            ->name('admin.dd.brackets.delete');

        // Withdrawals
        Route::get('/withdrawals', [WithdrawalController::class, 'index'])->name('admin.withdrawals.index');
        Route::post('/withdrawals/limits', [WithdrawalController::class, 'updateLimits'])->name('admin.withdrawals.limits');
        Route::post('/withdrawals/{transaction}/approve', [WithdrawalController::class, 'approve'])->name('admin.withdrawals.approve');
        Route::post('/withdrawals/{transaction}/deny', [WithdrawalController::class, 'deny'])->name('admin.withdrawals.deny');

        // City Grants
        Route::get('/grants/city', [CityGrantController::class, 'cityGrants'])->name(
            'admin.grants.city'
        );
        Route::post('/grants/city/{city_grant}/update', [CityGrantController::class, 'updateCityGrant'])
            ->name('admin.grants.city.update');

        Route::post('/grants/city/create', [CityGrantController::class, 'createCityGrant'])->name(
            'admin.grants.city.create'
        );

        Route::post('/grants/city/approve/{CityGrantRequest}', [CityGrantController::class, 'approveCityGrant'])->name(
            'admin.grants.city.approve'
        );

        Route::post('/grants/city/deny/{CityGrantRequest}', [CityGrantController::class, 'denyCityGrant'])->name(
            'admin.grants.city.deny'
        );

        // Grants
        Route::get('/grants', [AdminGrantController::class, 'grants'])->name('admin.grants');
        Route::post('/grants/create', [AdminGrantController::class, 'createGrant'])->name('admin.grants.create');
        Route::post('/grants/{grant}/update', [AdminGrantController::class, 'updateGrant'])->name(
            'admin.grants.update'
        );
        Route::post('/grants/{grant}/toggle', [AdminGrantController::class, 'toggleGrant'])->name(
            'admin.grants.toggle'
        );

        Route::post('/grants/approve/{application}', [AdminGrantController::class, 'approveApplication'])->name(
            'admin.grants.approve'
        )
            ->middleware(BlockWhenPWDown::class);
        Route::post('/grants/deny/{application}', [AdminGrantController::class, 'denyApplication'])->name(
            'admin.grants.deny'
        )
            ->middleware(BlockWhenPWDown::class);

        // Loan
        Route::get('/loans', [LoansController::class, 'index'])->name('admin.loans');
        Route::post('/loans/{Loan}/approve', [LoansController::class, 'approve'])->name(
            'admin.loans.approve'
        )->middleware(BlockWhenPWDown::class);
        Route::post('/loans/{Loan}/deny', [LoansController::class, 'deny'])->name(
            'admin.loans.deny'
        )->middleware(BlockWhenPWDown::class);
        Route::get('/loans/{Loan}/view', [LoansController::class, 'view'])->name(
            'admin.loans.view'
        );
        Route::post('/loans/{Loan}/update', [LoansController::class, 'update'])->name(
            'admin.loans.update'
        );

        Route::post('/loans/{Loan}/mark-paid', [LoansController::class, 'markAsPaid'])->name(
            'admin.loans.markPaid'
        )->middleware(BlockWhenPWDown::class);

        // Taxes
        Route::get('/taxes', [AdminTaxesController::class, 'index'])->name('admin.taxes');

        // Finance
        Route::get('/finance', [AllianceFinanceController::class, 'index'])->name('admin.finance.index');
        Route::get('/finance/export', [AllianceFinanceController::class, 'exportCsv'])->name('admin.finance.export');

        // Members
        Route::get('/members', [AdminMembersController::class, 'index'])->name('admin.members');
        Route::get('/members/{Nation}', [AdminMembersController::class, 'show'])->name('admin.members.show');

        // War
        Route::get('/defense/wars', [AdminWarController::class, 'index'])->name('admin.wars');

        // War Room & Campaign management
        Route::get('/war-room', [WarRoomController::class, 'index'])->name('admin.war-room');

        Route::post('/war-plans', [AdminWarPlanController::class, 'store'])->name('admin.war-plans.store');
        Route::get('/war-plans/{plan}', [AdminWarPlanController::class, 'show'])->name('admin.war-plans.show');
        Route::put('/war-plans/{plan}', [AdminWarPlanController::class, 'update'])->name('admin.war-plans.update');
        Route::post('/war-plans/{plan}/activate', [AdminWarPlanController::class, 'activate'])->name('admin.war-plans.activate');
        Route::post('/war-plans/{plan}/archive', [AdminWarPlanController::class, 'archive'])->name('admin.war-plans.archive');
        Route::post('/war-plans/{plan}/recompute', [AdminWarPlanController::class, 'recompute'])->name('admin.war-plans.recompute');
        Route::post('/war-plans/{plan}/auto-assign', [AdminWarPlanController::class, 'autoAssign'])->name('admin.war-plans.auto-assign');
        Route::post('/war-plans/{plan}/publish', [AdminWarPlanController::class, 'publish'])->name('admin.war-plans.publish');
        Route::get('/war-plans/{plan}/export', [AdminWarPlanController::class, 'export'])->name('admin.war-plans.export');
        Route::post('/war-plans/{plan}/import', [AdminWarPlanController::class, 'import'])->name('admin.war-plans.import');
        Route::post('/war-plans/{plan}/targets/{target}/war-type', [AdminWarPlanController::class, 'updateTargetWarType'])->name('admin.war-plans.targets.update-war-type');
        Route::post('/war-plans/{plan}/alliances', [AdminWarPlanController::class, 'addAlliance'])->name('admin.war-plans.alliances.store');
        Route::delete('/war-plans/{plan}/alliances/{alliance}', [AdminWarPlanController::class, 'removeAlliance'])->name('admin.war-plans.alliances.destroy');
        Route::post('/war-plans/{plan}/targets', [AdminWarPlanController::class, 'addTarget'])->name('admin.war-plans.targets.store');
        Route::delete('/war-plans/{plan}/targets/{target}', [AdminWarPlanController::class, 'removeTarget'])->name('admin.war-plans.targets.destroy');
        Route::post('/war-plans/{plan}/assignments/manual', [AdminWarPlanController::class, 'storeManualAssignment'])->name('admin.war-plans.assignments.manual');
        Route::delete('/war-plans/{plan}/assignments/{assignment}', [AdminWarPlanController::class, 'removeAssignment'])->name('admin.war-plans.assignments.destroy');

        Route::post('/war-counters', [AdminWarCounterController::class, 'store'])->name('admin.war-counters.store');
        Route::get('/war-counters/{counter}', [AdminWarCounterController::class, 'show'])->name('admin.war-counters.show');
        Route::post('/war-counters/{counter}/update', [AdminWarCounterController::class, 'update'])->name('admin.war-counters.update');
        Route::post('/war-counters/{counter}/auto-pick', [AdminWarCounterController::class, 'autoPick'])->name('admin.war-counters.auto-pick');
        Route::post('/war-counters/{counter}/assignments/manual', [AdminWarCounterController::class, 'storeManualAssignment'])->name('admin.war-counters.assignments.manual');
        Route::post('/war-counters/{counter}/assignments/{assignment}/assign', [AdminWarCounterController::class, 'assign'])->name('admin.war-counters.assignments.assign');
        Route::post('/war-counters/{counter}/assignments/{assignment}/unassign', [AdminWarCounterController::class, 'unassign'])->name('admin.war-counters.assignments.unassign');
        Route::delete('/war-counters/{counter}/assignments/{assignment}', [AdminWarCounterController::class, 'removeAssignment'])->name('admin.war-counters.assignments.destroy');
        Route::post('/war-counters/{counter}/finalize', [AdminWarCounterController::class, 'finalize'])->name('admin.war-counters.finalize');
        Route::post('/war-counters/{counter}/archive', [AdminWarCounterController::class, 'archive'])->name('admin.war-counters.archive');

        // War Aid
        Route::get('/defense/waraid', [AdminWarAidControllerAlias::class, 'index'])->name(
            'admin.war-aid'
        );
        Route::patch(
            '/defense/waraid/{WarAidRequest}/approve',
            [AdminWarAidControllerAlias::class, 'approve']
        )->name('admin.war-aid.approve')->middleware(BlockWhenPWDown::class);
        Route::patch(
            '/defense/waraid/{WarAidRequest}/deny',
            [AdminWarAidControllerAlias::class, 'deny']
        )->name('admin.war-aid.deny')->middleware(BlockWhenPWDown::class);
        Route::post('/defense/waraid/toggle', [AdminWarAidControllerAlias::class, 'toggle'])->name(
            'admin.war-aid.toggle'
        );

        Route::get('/defense/raids', [RaidController::class, 'index'])->name('admin.raids.index');
        Route::post('/defense/raids/no-raid', [RaidController::class, 'storeNoRaid'])->name(
            'admin.raids.no-raid.store'
        );
        Route::delete('/defense/raids/no-raid/{id}', [RaidController::class, 'destroyNoRaid'])->name(
            'admin.raids.no-raid.destroy'
        );
        Route::post('/defense/raids/top-cap', [RaidController::class, 'updateTopCap'])->name(
            'admin.raids.top-cap.update'
        );

        Route::get('/recruitment', [RecruitmentController::class, 'index'])->name('admin.recruitment.index');
        Route::post('/recruitment', [RecruitmentController::class, 'update'])->name('admin.recruitment.update');
        Route::post('/recruitment/test', [RecruitmentController::class, 'sendTest'])->name(
            'admin.recruitment.test'
        )->middleware(BlockWhenPWDown::class);

        Route::get('/settings', [SettingsController::class, 'index'])->name('admin.settings');
        Route::post('/settings/sync/nations', [SettingsController::class, 'runSyncNation'])->name(
            'admin.settings.sync.run'
        )->middleware(BlockWhenPWDown::class);
        Route::post('/settings/sync/alliances', [SettingsController::class, 'runSyncAlliance'])->name(
            'admin.settings.sync.alliances'
        )->middleware(BlockWhenPWDown::class);

        Route::post('/settings/sync/wars', [SettingsController::class, 'runSyncWar'])->name(
            'admin.settings.sync.wars'
        )->middleware(BlockWhenPWDown::class);
        Route::post('/settings/sync/cancel', [SettingsController::class, 'cancelSync'])->name(
            'admin.settings.sync.cancel'
        );

        Route::prefix('mmr')->group(function () {
            Route::get('/', [MMRController::class, 'index'])->name('admin.mmr.index');
            Route::post('/store', [MMRController::class, 'store'])->name('admin.mmr.store');
            Route::delete('/destroy', [MMRController::class, 'destroy'])->name('admin.mmr.destroy');
            Route::post('/{tier}/update', [MMRController::class, 'update'])->name('admin.mmr.update');
            Route::post('/update-all', [MMRController::class, 'updateAll'])->name('admin.mmr.updateAll');
            Route::post('/update-mmr-assistant-settings', [MMRController::class, 'updateAssistantSettings'])->name('admin.mmr.assistant.update');
        });

        Route::prefix('customization')
            ->middleware('can:manage-custom-pages')
            ->group(function () {
                Route::get('/', [CustomizationController::class, 'index'])->name('admin.customization.index');
                Route::get('/pages/{page}', [CustomizationController::class, 'edit'])->name('admin.customization.edit');
                Route::post('/pages/{page}/preview', [CustomizationController::class, 'preview'])->name('admin.customization.preview');
                Route::post('/pages/{page}/draft', [CustomizationController::class, 'saveDraft'])->name('admin.customization.draft');
                Route::post('/pages/{page}/publish', [CustomizationController::class, 'publish'])->name('admin.customization.publish');
                Route::get('/pages/{page}/versions', [CustomizationController::class, 'versions'])->name('admin.customization.versions');
                Route::post('/pages/{page}/restore', [CustomizationController::class, 'restore'])->name('admin.customization.restore');

                Route::post('/images', [CustomizationImageController::class, 'store'])->name('admin.customization.images.store');
                Route::get('/images/{token}', [CustomizationImageController::class, 'show'])
                    ->middleware('signed')
                    ->name('admin.customization.images.show');
            });

    });
