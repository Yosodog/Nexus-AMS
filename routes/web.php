<?php

use App\Http\Controllers\AccountsController;
use App\Http\Controllers\Admin\AccountController;
use App\Http\Controllers\Admin\AllianceFinanceController;
use App\Http\Controllers\Admin\ApplicationController as AdminApplicationController;
use App\Http\Controllers\Admin\AuditController as AdminAuditController;
use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\AuditRuleController;
use App\Http\Controllers\Admin\CityController;
use App\Http\Controllers\Admin\CityGrantController;
use App\Http\Controllers\Admin\CustomizationController;
use App\Http\Controllers\Admin\CustomizationImageController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\GrantController as AdminGrantController;
use App\Http\Controllers\Admin\LoansController;
use App\Http\Controllers\Admin\ManualDisbursementController;
use App\Http\Controllers\Admin\MarketController as AdminMarketController;
use App\Http\Controllers\Admin\MembersController as AdminMembersController;
use App\Http\Controllers\Admin\MemberTransferController as AdminMemberTransferController;
use App\Http\Controllers\Admin\MMRController;
use App\Http\Controllers\Admin\NelDocsController;
use App\Http\Controllers\Admin\OffshoreController;
use App\Http\Controllers\Admin\PayrollController;
use App\Http\Controllers\Admin\RaidController;
use App\Http\Controllers\Admin\RecruitmentController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\SpyCampaignController;
use App\Http\Controllers\Admin\TaxesController as AdminTaxesController;
use App\Http\Controllers\Admin\UserController as AdminUserController;
use App\Http\Controllers\Admin\WarAidController as AdminWarAidControllerAlias;
use App\Http\Controllers\Admin\WarController as AdminWarController;
use App\Http\Controllers\Admin\WarCounterController as AdminWarCounterController;
use App\Http\Controllers\Admin\WarPlanController as AdminWarPlanController;
use App\Http\Controllers\Admin\WarRoomController;
use App\Http\Controllers\Admin\WithdrawalController;
use App\Http\Controllers\ApiDocsController;
use App\Http\Controllers\ApplyPageController;
use App\Http\Controllers\AuditController;
use App\Http\Controllers\CityGrantController as UserCityGrantController;
use App\Http\Controllers\CounterFinderController;
use App\Http\Controllers\DirectDepositController;
use App\Http\Controllers\DiscordVerificationController;
use App\Http\Controllers\GrantController as UserGrantController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\IntelReportController;
use App\Http\Controllers\LoansController as UserLoansController;
use App\Http\Controllers\MemberTransferController;
use App\Http\Controllers\MarketController;
use App\Http\Controllers\RaidFinderController;
use App\Http\Controllers\RaidingLeaderboardController;
use App\Http\Controllers\SpyAssignmentController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\VerificationController;
use App\Http\Controllers\WarAidController;
use App\Http\Controllers\WarSimulatorController;
use App\Http\Controllers\WarStatsController;
use App\Http\Middleware\AdminMiddleware;
use App\Http\Middleware\BlockWhenPWDown;
use App\Http\Middleware\DiscordVerifiedMiddleware;
use App\Http\Middleware\EnsureUserIsVerified;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');

Route::get('/apply', [ApplyPageController::class, 'show'])->name('apply.show');

Route::middleware(['auth'])->group(function () {
    // Verification
    Route::get('/verify/{code}', [VerificationController::class, 'verify'])->name('verify');
    Route::get('/notverified', [VerificationController::class, 'notVerified'])->name(
        'not_verified'
    );
    Route::post('/resend-verification', [VerificationController::class, 'resendVerification'])
        ->name('verification.resend');

    Route::get('/verify-discord', [DiscordVerificationController::class, 'show'])->name('discord.verify.show');
    Route::post('/verify-discord/regenerate', [DiscordVerificationController::class, 'regenerateToken'])->name(
        'discord.token.regenerate'
    );
    Route::post('/verify-discord/unlink', [DiscordVerificationController::class, 'unlink'])->name(
        'discord.unlink'
    );
});

Route::middleware(['auth', EnsureUserIsVerified::class, DiscordVerifiedMiddleware::class])->group(callback: function () {
    // User settings
    Route::get('/user/settings', [UserController::class, 'settings'])->name('user.settings');
    Route::post('/user/settings/update', [UserController::class, 'updateSettings'])->name(
        'user.settings.update'
    );
    Route::post('/user/settings/api-tokens', [UserController::class, 'storeApiToken'])->name(
        'user.settings.api-tokens.store'
    );
    Route::post('/user/settings/api-tokens/{tokenId}/regenerate', [UserController::class, 'regenerateApiToken'])->name(
        'user.settings.api-tokens.regenerate'
    );
    Route::post('/user/settings/api-tokens/{tokenId}/revoke', [UserController::class, 'revokeApiToken'])->name(
        'user.settings.api-tokens.revoke'
    );
    Route::get('/user/settings/api-docs', ApiDocsController::class)->name('user.settings.api-docs');

    // User dashboard
    Route::get('/user/dashboard', [UserController::class, 'dashboard'])->name('user.dashboard');

    // Account Routes
    Route::get('/accounts', [AccountsController::class, 'index'])->name('accounts');
    Route::post('accounts/transfer', [AccountsController::class, 'transfer'])
        ->name('accounts.transfer')
        ->middleware([BlockWhenPWDown::class, 'throttle:account-transfers']);

    Route::get('/accounts/member-transfer-search', [MemberTransferController::class, 'search'])
        ->name('member-transfers.search');
    Route::post('/accounts/member-transfers/{memberTransfer}/accept', [MemberTransferController::class, 'accept'])
        ->name('member-transfers.accept');
    Route::post('/accounts/member-transfers/{memberTransfer}/decline', [MemberTransferController::class, 'decline'])
        ->name('member-transfers.decline');
    Route::post('/accounts/member-transfers/{memberTransfer}/cancel', [MemberTransferController::class, 'cancel'])
        ->name('member-transfers.cancel');

    Route::post('/accounts/auto-withdraw', [AccountsController::class, 'updateAutoWithdraw'])
        ->name('auto-withdraw.update');

    Route::get('/accounts/create', [AccountsController::class, 'createView'])->name('accounts.create');
    Route::post('/accounts/create', [AccountsController::class, 'create'])->name('accounts.create.post');

    Route::post('/accounts/delete', [AccountsController::class, 'delete'])->name('accounts.delete.post');

    Route::get('/accounts/{accounts}', [AccountsController::class, 'viewAccount'])->name('accounts.view');

    // Alliance Market
    Route::get('/market', [MarketController::class, 'index'])->name('market.index');
    Route::post('/market/sell', [MarketController::class, 'sell'])->name('market.sell');

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

    // Audits
    Route::get('/audit', [AuditController::class, 'index'])->name('audit.index');

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

        Route::get('/war-stats', WarStatsController::class)->name('defense.war-stats');
        Route::get('/simulators', WarSimulatorController::class)->name('defense.simulators');
        Route::get('/raid-leaderboard', RaidingLeaderboardController::class)->name('defense.raid-leaderboard');
        Route::get('/intel', [IntelReportController::class, 'index'])->name('defense.intel');
        Route::post('/intel', [IntelReportController::class, 'store'])->name('defense.intel.store');
    });
    Route::get('/spy-ops', [SpyAssignmentController::class, 'index'])->name('spy.assignments');
    // Counters

    // Grants
    Route::prefix('grants')->middleware(['auth'])->group(function () {
        // City grants
        Route::get('/city', [UserCityGrantController::class, 'index'])->name('grants.city');
        Route::post('/city', [UserCityGrantController::class, 'request'])->name(
            'grants.city.request'
        )
            ->middleware([BlockWhenPWDown::class, 'throttle:grant-requests']);

        Route::get('{grant:slug}', [UserGrantController::class, 'show'])->name('grants.show_grants');
        Route::post('{grant:slug}/apply', [UserGrantController::class, 'apply'])->name('grants.apply')
            ->middleware([BlockWhenPWDown::class, 'throttle:grant-requests']);
    });
});

Route::middleware(['auth', EnsureUserIsVerified::class, DiscordVerifiedMiddleware::class, AdminMiddleware::class])
    ->prefix('admin')
    ->group(function () {
        // Base routes
        Route::get('/', [DashboardController::class, 'dashboard'])->name('admin.dashboard');

        // Users
        Route::get('/users', [AdminUserController::class, 'index'])->name('admin.users.index');
        Route::get('/user/{user}/edit', [AdminUserController::class, 'edit'])->name('admin.users.edit');
        Route::put('/user/{user}', [AdminUserController::class, 'update'])->name('admin.users.update');
        Route::post('/user/{user}/discord/unlink', [AdminUserController::class, 'unlinkDiscord'])->name(
            'admin.users.discord.unlink'
        );

        Route::post('/member-transfers/{memberTransfer}/cancel', [AdminMemberTransferController::class, 'cancel'])
            ->name('admin.member-transfers.cancel');

        // Roles
        Route::get('/roles', [RoleController::class, 'index'])->name('admin.roles.index');
        Route::get('/roles/create', [RoleController::class, 'create'])->name('admin.roles.create');
        Route::post('/roles', [RoleController::class, 'store'])->name('admin.roles.store');
        Route::get('/roles/{role}/edit', [RoleController::class, 'edit'])->name('admin.roles.edit');
        Route::put('/roles/{role}', [RoleController::class, 'update'])->name('admin.roles.update');
        Route::delete('/roles/{role}', [RoleController::class, 'destroy'])->name('admin.roles.destroy');

        // Audits
        Route::get('/audits', [AdminAuditController::class, 'index'])->name('admin.audits.index');
        Route::get('/audits/rules', [AuditRuleController::class, 'index'])->name('admin.audits.rules.index');
        Route::get('/audits/rules/create', [AuditRuleController::class, 'create'])->name('admin.audits.rules.create');
        Route::post('/audits/rules', [AuditRuleController::class, 'store'])->name('admin.audits.rules.store');
        Route::get('/audits/rules/{auditRule}/edit', [AuditRuleController::class, 'edit'])->name('admin.audits.rules.edit');
        Route::put('/audits/rules/{auditRule}', [AuditRuleController::class, 'update'])->name('admin.audits.rules.update');
        Route::delete('/audits/rules/{auditRule}', [AuditRuleController::class, 'destroy'])->name('admin.audits.rules.destroy');
        Route::get('/audits/rules/{auditRule}/violations', [AdminAuditController::class, 'violations'])
            ->name('admin.audits.rules.violations');
        Route::post('/audits/run', [AdminAuditController::class, 'run'])->name('admin.audits.run');
        Route::post('/audits/notify', [AdminAuditController::class, 'notify'])->name('admin.audits.notify');
        Route::get('/audit-logs', [AuditLogController::class, 'index'])->name('admin.audit-logs.index');

        // Account
        Route::get('/accounts', [AccountController::class, 'dashboard'])->name('admin.accounts.dashboard');
        Route::get('/accounts/{accounts}', [AccountController::class, 'view'])->name('admin.accounts.view');
        Route::post('/accounts/{account}/adjust', [AccountController::class, 'adjustBalance'])->name(
            'admin.accounts.adjust'
        );
        Route::post('/accounts/{account}/freeze', [AccountController::class, 'freeze'])->name(
            'admin.accounts.freeze'
        );

        // Alliance Market
        Route::get('/market', [AdminMarketController::class, 'index'])->name('admin.market.index');
        Route::post('/market/resource/{marketResource}/toggle', [AdminMarketController::class, 'toggle'])->name(
            'admin.market.resource.toggle'
        );
        Route::post('/market/resource/{marketResource}/update', [AdminMarketController::class, 'update'])->name(
            'admin.market.resource.update'
        );
        Route::post('/accounts/{account}/unfreeze', [AccountController::class, 'unfreeze'])->name(
            'admin.accounts.unfreeze'
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

        Route::post('/grants/city/reminders', [CityGrantController::class, 'sendReminders'])->name(
            'admin.grants.city.reminders'
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
        Route::post('/loans/default-interest-rate', [LoansController::class, 'updateDefaultInterestRate'])->name(
            'admin.loans.default-interest-rate'
        );
        Route::post('/loans/applications', [LoansController::class, 'updateLoanApplications'])->name(
            'admin.loans.applications'
        );

        Route::post('/loans/{Loan}/mark-paid', [LoansController::class, 'markAsPaid'])->name(
            'admin.loans.markPaid'
        )->middleware(BlockWhenPWDown::class);

        // Manual disbursements
        Route::post('/manual-disbursements/grants', [ManualDisbursementController::class, 'sendGrant'])->name(
            'admin.manual-disbursements.grants'
        )->middleware(BlockWhenPWDown::class);
        Route::post('/manual-disbursements/city-grants', [ManualDisbursementController::class, 'sendCityGrant'])->name(
            'admin.manual-disbursements.city-grants'
        )->middleware(BlockWhenPWDown::class);
        Route::post('/manual-disbursements/loans', [ManualDisbursementController::class, 'sendLoan'])->name(
            'admin.manual-disbursements.loans'
        )->middleware(BlockWhenPWDown::class);
        Route::post('/manual-disbursements/war-aid', [ManualDisbursementController::class, 'sendWarAid'])->name(
            'admin.manual-disbursements.war-aid'
        )->middleware(BlockWhenPWDown::class);

        // Taxes
        Route::get('/taxes', [AdminTaxesController::class, 'index'])->name('admin.taxes');

        // Finance
        Route::get('/finance', [AllianceFinanceController::class, 'index'])->name('admin.finance.index');
        Route::get('/finance/export', [AllianceFinanceController::class, 'exportCsv'])->name('admin.finance.export');

        // Payroll
        Route::get('/payroll', [PayrollController::class, 'index'])->name('admin.payroll.index');
        Route::post('/payroll/grades', [PayrollController::class, 'storeGrade'])->name('admin.payroll.grades.store');
        Route::put('/payroll/grades/{payrollGrade}', [PayrollController::class, 'updateGrade'])
            ->name('admin.payroll.grades.update');
        Route::delete('/payroll/grades/{payrollGrade}', [PayrollController::class, 'destroyGrade'])
            ->name('admin.payroll.grades.destroy');
        Route::post('/payroll/members', [PayrollController::class, 'storeMember'])->name('admin.payroll.members.store');
        Route::put('/payroll/members/{payrollMember}', [PayrollController::class, 'updateMember'])
            ->name('admin.payroll.members.update');
        Route::delete('/payroll/members/{payrollMember}', [PayrollController::class, 'destroyMember'])
            ->name('admin.payroll.members.destroy');

        // Members
        Route::get('/members', [AdminMembersController::class, 'index'])->name('admin.members');
        Route::get('/members/{Nation}', [AdminMembersController::class, 'show'])->name('admin.members.show');
        Route::post('/members/inactivity-settings', [AdminMembersController::class, 'updateInactivitySettings'])
            ->name('admin.members.inactivity-settings');
        Route::post('/members/inactivity-check', [AdminMembersController::class, 'runInactivityCheck'])
            ->name('admin.members.inactivity-check');

        // War
        Route::get('/defense/wars', [AdminWarController::class, 'index'])->name('admin.wars');

        // War Room & Campaign management
        Route::get('/war-room', [WarRoomController::class, 'index'])->name('admin.war-room');
        Route::post('/war-room/discord-channel', [WarRoomController::class, 'updateDiscordChannel'])
            ->name('admin.war-room.discord-channel');

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

        // Spy Campaigns
        Route::get('/spy-campaigns', [SpyCampaignController::class, 'index'])->name('admin.spy-campaigns.index');
        Route::post('/spy-campaigns', [SpyCampaignController::class, 'store'])->name('admin.spy-campaigns.store');
        Route::get('/spy-campaigns/{spyCampaign}', [SpyCampaignController::class, 'show'])->name('admin.spy-campaigns.show');
        Route::put('/spy-campaigns/{spyCampaign}', [SpyCampaignController::class, 'update'])->name('admin.spy-campaigns.update');
        Route::post('/spy-campaigns/{spyCampaign}/alliances', [SpyCampaignController::class, 'addAlliance'])->name('admin.spy-campaigns.alliances.store');
        Route::delete('/spy-campaigns/{spyCampaign}/alliances/{spyCampaignAlliance}', [SpyCampaignController::class, 'removeAlliance'])->name('admin.spy-campaigns.alliances.destroy');
        Route::post('/spy-campaigns/{spyCampaign}/rounds', [SpyCampaignController::class, 'addRound'])->name('admin.spy-campaigns.rounds.store');
        Route::post('/spy-campaign-rounds/{spyRound}/generate', [SpyCampaignController::class, 'generate'])->name('admin.spy-campaigns.rounds.generate');
        Route::post('/spy-campaign-rounds/{spyRound}/message', [SpyCampaignController::class, 'sendMessages'])->name('admin.spy-campaigns.rounds.message');
        Route::get('/spy-campaign-rounds/{spyRound}', [SpyCampaignController::class, 'round'])->name('admin.spy-campaigns.rounds.show');

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

        Route::get('/nel', NelDocsController::class)->name('admin.nel.docs');

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
        Route::post('/settings/discord', [SettingsController::class, 'updateDiscordRequirement'])->name(
            'admin.settings.discord'
        );
        Route::post('/settings/discord/departure', [SettingsController::class, 'updateDiscordDeparture'])->name(
            'admin.settings.discord.departure'
        );
        Route::post('/settings/homepage', [SettingsController::class, 'updateHomepage'])->name(
            'admin.settings.homepage'
        );
        Route::post('/settings/favicon', [SettingsController::class, 'updateFavicon'])->name(
            'admin.settings.favicon'
        );
        Route::post('/settings/auto-withdraw', [SettingsController::class, 'updateAutoWithdraw'])->name(
            'admin.settings.auto-withdraw'
        );
        Route::post('/settings/loan-payments', [SettingsController::class, 'updateLoanPayments'])->name(
            'admin.settings.loan-payments'
        );
        Route::post('/settings/grants/approvals', [SettingsController::class, 'updateGrantApprovals'])->name(
            'admin.settings.grants.approvals'
        );
        Route::post('/settings/audit-retention', [SettingsController::class, 'updateAuditRetention'])->name(
            'admin.settings.audit-retention'
        );

        Route::prefix('mmr')->group(function () {
            Route::get('/', [MMRController::class, 'index'])->name('admin.mmr.index');
            Route::post('/store', [MMRController::class, 'store'])->name('admin.mmr.store');
            Route::delete('/destroy', [MMRController::class, 'destroy'])->name('admin.mmr.destroy');
            Route::post('/{tier}/update', [MMRController::class, 'update'])->name('admin.mmr.update');
            Route::post('/update-all', [MMRController::class, 'updateAll'])->name('admin.mmr.updateAll');
            Route::post('/bulk-edit-resources', [MMRController::class, 'bulkEditResources'])->name('admin.mmr.bulk-edit-resources');
            Route::post('/update-weights', [MMRController::class, 'updateWeights'])->name('admin.mmr.weights.update');
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
