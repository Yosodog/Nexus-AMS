<?php

namespace App\Http\Controllers;

use App\Models\CityGrant;
use App\Models\CityGrantRequest;
use App\Services\AccountService;
use App\Services\CityCostService;
use App\Services\CityGrantService;
use App\Services\SettingService;
use Exception;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class CityGrantController
{
    public function index(): View
    {
        $nation = Auth::user()->nation;
        $grants = CityGrant::all();
        $accounts = $nation->accounts;
        $nextCityNumber = $nation->num_cities + 1;
        $grantRequests = CityGrantRequest::where('nation_id', $nation->id)->orderBy('created_at', 'desc')->get();
        $cityCostService = app(CityCostService::class);
        $cityAverage = $cityCostService->getTop20Average();
        $cityAverageUpdatedAt = SettingService::getCityAverageUpdatedAt();
        $grantAmounts = $grants->mapWithKeys(
            fn (CityGrant $grant) => [$grant->id => $cityCostService->calculateGrantAmount($grant)]
        );

        return view('grants.city_grants', compact(
            'grants',
            'accounts',
            'nextCityNumber',
            'grantRequests',
            'grantAmounts',
            'cityAverage',
            'cityAverageUpdatedAt'
        ));
    }

    /**
     * @return mixed
     */
    public function request(Request $request): RedirectResponse
    {
        try {
            // First, ensure they own the account they're requesting for
            $account = AccountService::getAccountById($request->input('account_id'));

            if ($account->nation_id != Auth::user()->nation_id) {
                abort(403, "You don't own that account");
            }

            // Get the city grant that they are getting
            $nation = Auth::user()->nation;
            $cityGrant = CityGrantService::findGrantWithCityNum($nation->num_cities + 1);

            // Now validate that this nation is actually eligible
            CityGrantService::validateEligibility($cityGrant, $nation);

            // If no exceptions were thrown, they are eligible, so create the request
            CityGrantService::createRequest($cityGrant, $nation->id, $account->id);
        } catch (ValidationException $exception) {
            $details = collect($exception->errors())->flatten()->implode(' ');

            return redirect()
                ->route('grants.city')
                ->with([
                    'alert-message' => trim('You are not eligible for this grant. '.$details),
                    // TODO be more specific lol
                    'alert-type' => 'error',
                ]);
        } catch (Exception $exception) {
            return redirect()
                ->route('grants.city')
                ->with([
                    'alert-message' => 'There was some kind of error when requesting your city grant. Please contact someone from the economics team.'
                        .$exception->getMessage(), // TODO log this error
                    'alert-type' => 'error',
                ]);
        }

        return redirect()
            ->route('grants.city')
            ->with([
                'alert-message' => 'City grant requested! Please give us up to 24 hours to review your request.',
                'alert-type' => 'success',
            ]);
    }
}
