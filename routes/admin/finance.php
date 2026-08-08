<?php

use App\Http\Controllers\Admin\AccountController;
use App\Http\Controllers\Admin\AllianceFinanceController;
use App\Http\Controllers\Admin\CityController;
use App\Http\Controllers\Admin\CityGrantController;
use App\Http\Controllers\Admin\GrantController as AdminGrantController;
use App\Http\Controllers\Admin\GrowthCirclesController as AdminGrowthCirclesController;
use App\Http\Controllers\Admin\LoansController;
use App\Http\Controllers\Admin\LotteryController as AdminLotteryController;
use App\Http\Controllers\Admin\ManualDisbursementController;
use App\Http\Controllers\Admin\MarketController as AdminMarketController;
use App\Http\Controllers\Admin\OffshoreController;
use App\Http\Controllers\Admin\PayrollController;
use App\Http\Controllers\Admin\TaxesController as AdminTaxesController;
use App\Http\Controllers\Admin\WithdrawalController;
use App\Http\Middleware\BlockWhenPWDown;
use Illuminate\Support\Facades\Route;

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
Route::get('/lottery', [AdminLotteryController::class, 'index'])->name('admin.lottery.index');
Route::post('/lottery/settings', [AdminLotteryController::class, 'update'])->name('admin.lottery.settings.update');
Route::post('/accounts/{account}/unfreeze', [AccountController::class, 'unfreeze'])->name(
    'admin.accounts.unfreeze'
);
Route::post('/accounts/transactions/{transaction}/refund', [AccountController::class, 'refundTransaction'])
    ->name('admin.accounts.transactions.refund')
    ->middleware(BlockWhenPWDown::class);
Route::post('/accounts/transactions/{transaction}/unstuck-refund', [AccountController::class, 'unstuckAndRefundTransaction'])
    ->name('admin.accounts.transactions.unstuck_refund')
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
Route::post('/offshores/{offshore}/sweep', [OffshoreController::class, 'sweepToOffshore'])
    ->name('admin.offshores.sweep')
    ->middleware(BlockWhenPWDown::class);
Route::post('/offshores/transfer', [OffshoreController::class, 'transfer'])
    ->name('admin.offshores.transfer')
    ->middleware(BlockWhenPWDown::class);

Route::post('/direct-deposit/settings', [AccountController::class, 'saveDirectDepositSettings'])
    ->name('admin.dd.settings');

Route::post('/direct-deposit/brackets/create', [AccountController::class, 'createDirectDepositBracket'])
    ->name('admin.dd.brackets.create');

Route::post('/direct-deposit/brackets/update', [AccountController::class, 'updateDirectDepositBrackets'])
    ->name('admin.dd.brackets.update');

Route::post('/direct-deposit/brackets/delete', [AccountController::class, 'deleteDirectDepositBrackets'])
    ->name('admin.dd.brackets.delete');

// Growth Circles
Route::get('/growth-circles', [AdminGrowthCirclesController::class, 'index'])
    ->name('admin.growth-circles.index');

Route::get('/growth-circles/history', [AdminGrowthCirclesController::class, 'history'])
    ->name('admin.growth-circles.history');

Route::post('/growth-circles/settings', [AdminGrowthCirclesController::class, 'saveSettings'])
    ->name('admin.growth-circles.settings');

Route::post('/growth-circles/enrollments/{nation}/disenroll', [AdminGrowthCirclesController::class, 'forceDisenroll'])
    ->name('admin.growth-circles.force-disenroll')
    ->middleware(BlockWhenPWDown::class);

Route::post('/growth-circles/enrollments/{nation}/reapply-bracket', [AdminGrowthCirclesController::class, 'reapplyBracket'])
    ->name('admin.growth-circles.reapply-bracket')
    ->middleware(BlockWhenPWDown::class);

// Withdrawals
Route::get('/withdrawals', [WithdrawalController::class, 'index'])->name('admin.withdrawals.index');
Route::post('/withdrawals/limits', [WithdrawalController::class, 'updateLimits'])->name('admin.withdrawals.limits');
Route::post('/withdrawals/{transaction}/approve', [WithdrawalController::class, 'approve'])->name('admin.withdrawals.approve');
Route::post('/withdrawals/{transaction}/deny', [WithdrawalController::class, 'deny'])->name('admin.withdrawals.deny');
Route::post('/withdrawals/{transaction}/reconcile', [WithdrawalController::class, 'reconcile'])->name('admin.withdrawals.reconcile');

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
Route::get('/finance/day/{date}', [AllianceFinanceController::class, 'dayDetails'])->name('admin.finance.day');
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
