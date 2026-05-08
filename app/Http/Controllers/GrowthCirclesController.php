<?php

namespace App\Http\Controllers;

use App\Exceptions\UserErrorException;
use App\Http\Requests\EnrollGrowthCirclesRequest;
use App\Models\Account;
use App\Services\GrowthCircleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

class GrowthCirclesController extends Controller
{
    public function __construct(
        protected GrowthCircleService $growthCircles,
    ) {}

    public function enroll(EnrollGrowthCirclesRequest $request): RedirectResponse
    {
        $nation = Auth::user()->nation;
        $account = Account::findOrFail($request->validated()['account_id']);

        try {
            $this->growthCircles->enroll($nation, $account);
        } catch (UserErrorException $e) {
            return back()->with([
                'alert-message' => $e->getMessage(),
                'alert-type' => 'error',
            ]);
        }

        return back()->with([
            'alert-message' => 'You have been enrolled in Growth Circles.',
            'alert-type' => 'success',
        ]);
    }
}
