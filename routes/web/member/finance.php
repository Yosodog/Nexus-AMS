<?php

use App\Http\Controllers\AccountsController;
use App\Http\Controllers\AccountStatementController;
use App\Http\Controllers\DirectDepositController;
use App\Http\Controllers\GrowthCirclesController;
use App\Http\Controllers\LoansController as UserLoansController;
use App\Http\Controllers\LotteryController;
use App\Http\Controllers\MarketController;
use App\Http\Controllers\MemberTransferController;
use App\Http\Middleware\BlockWhenPWDown;
use Illuminate\Support\Facades\Route;

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

Route::get('/account-statements', [AccountStatementController::class, 'index'])
    ->name('accounts.statements.index');
Route::get('/account-statements/print', [AccountStatementController::class, 'print'])
    ->name('accounts.statements.print');
Route::post('/account-statements/exports', [AccountStatementController::class, 'store'])
    ->middleware('throttle:5,1')
    ->name('accounts.statements.exports.store');
Route::get('/account-statements/exports/{statementExport}', [AccountStatementController::class, 'show'])
    ->name('accounts.statements.exports.show');
Route::get('/account-statements/exports/{statementExport}/download', [AccountStatementController::class, 'download'])
    ->name('accounts.statements.exports.download');

// Alliance Market
Route::get('/market', [MarketController::class, 'index'])->name('market.index');
Route::post('/market/sell', [MarketController::class, 'sell'])->name('market.sell');

// Weekly Lottery
Route::get('/lottery', [LotteryController::class, 'index'])->name('lottery.index');
Route::post('/lottery/tickets', [LotteryController::class, 'store'])
    ->name('lottery.tickets.store')
    ->middleware('throttle:lottery-purchases');

// Direct Deposit
Route::post('/direct-deposit/enroll', [DirectDepositController::class, 'enroll'])->name('dd.enroll')
    ->middleware(BlockWhenPWDown::class);
Route::post('/direct-deposit/disenroll', [DirectDepositController::class, 'disenroll'])->name('dd.disenroll')
    ->middleware(BlockWhenPWDown::class);

// Growth Circles
Route::post('/growth-circles/enroll', [GrowthCirclesController::class, 'enroll'])
    ->name('growth-circles.enroll')
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
