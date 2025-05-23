<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Services\DirectDepositService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DirectDepositController extends Controller
{
    /**
     * @var DirectDepositService
     */
    public DirectDepositService $directDepositService;

    /**
     *
     */
    public function __construct()
    {
        $this->directDepositService = app(DirectDepositService::class);
    }

    /**
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function enroll(Request $request)
    {
        $request->validate(['account_id' => 'required|exists:accounts,id']);

        $nation = Auth::user()->nation;
        $account = Account::findOrFail($request->account_id);

        $this->directDepositService->enroll($nation, $account);

        return back()->with([
            'alert-message' => 'You have been enrolled in Direct Deposit.',
            'alert-type' => 'success'
        ]);
    }

    /**
     * @return \Illuminate\Http\RedirectResponse
     */
    public function disenroll()
    {
        $nation = Auth::user()->nation;

        $this->directDepositService->disenroll($nation);

        return back()->with([
            'alert-message' => 'You have been disenrolled from Direct Deposit.',
            'alert-type' => 'success'
        ]);
    }
}
