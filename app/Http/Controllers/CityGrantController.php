<?php

namespace App\Http\Controllers;

use App\Enums\CityGrantFailureReason;
use App\Exceptions\CityGrantRequestException;
use App\Http\Requests\StoreCityGrantRequest;
use App\Models\CityGrant;
use App\Models\CityGrantRequest;
use App\Services\CityCostService;
use App\Services\CityGrantService;
use App\Services\GrantRequirementService;
use App\Services\SettingService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class CityGrantController
{
    public function index(): View
    {
        $nation = Auth::user()->nation;
        $grants = CityGrant::query()
            ->where('enabled', true)
            ->orderBy('city_number')
            ->get();
        $accounts = $nation->accounts;
        $nextCityNumber = $nation->num_cities + 1;
        $requirementService = app(GrantRequirementService::class);

        $grants->each(function (CityGrant $grant) use ($nation, $requirementService): void {
            $inspection = $requirementService->inspect($grant->requirements);

            if ($inspection['errors'] !== []) {
                Log::warning('City grant page encountered malformed stored requirements.', [
                    'city_grant_id' => $grant->id,
                    'city_number' => $grant->city_number,
                    'nation_id' => $nation->id,
                    'errors' => $inspection['errors'],
                ]);
            }

            $grant->requirements = $inspection['normalized'];
            $grant->setAttribute('requirements_valid', $inspection['errors'] === []);
            $grant->setAttribute(
                'requirement_summary',
                $inspection['errors'] === []
                    ? $requirementService->summarize($inspection['normalized'])
                    : ['City grant requirements are unavailable because the saved configuration is invalid.']
            );
        });

        $nextGrant = $grants->firstWhere('city_number', $nextCityNumber);
        $nextEligibilityReport = match (true) {
            $nextGrant === null => [
                'passes' => false,
                'failures' => ['No city grant is currently available for your next city.'],
                'summary' => [],
            ],
            ! $nextGrant->getAttribute('requirements_valid') => [
                'passes' => false,
                'failures' => ['This city grant has invalid eligibility requirements. Please contact an administrator.'],
                'summary' => $nextGrant->requirement_summary,
            ],
            default => $requirementService->evaluate($nextGrant->requirements, $nation),
        };
        $grantRequests = CityGrantRequest::where('nation_id', $nation->id)
            ->orderByDesc('city_number')
            ->orderBy('created_at', 'desc')
            ->get();
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
            'cityAverageUpdatedAt',
            'nextEligibilityReport'
        ));
    }

    public function request(StoreCityGrantRequest $request): RedirectResponse
    {
        try {
            $accountId = (int) $request->validated('account_id');
            $nation = Auth::user()->nation;
            $account = $nation->accounts()->findOrFail($accountId);
            $cityGrant = CityGrantService::findGrantWithCityNum($nation->num_cities + 1);

            CityGrantService::createRequest($cityGrant, $nation, $account->id);
        } catch (CityGrantRequestException $exception) {
            return $this->failureResponse($request, $this->failureMessage($exception->reason));
        } catch (Throwable $exception) {
            $referenceId = (string) Str::uuid();

            Log::error('City grant request failed unexpectedly.', [
                'reference_id' => $referenceId,
                'nation_id' => Auth::user()?->nation_id,
                'account_id' => $request->input('account_id'),
                'exception_class' => $exception::class,
                'exception' => $exception,
            ]);

            return $this->failureResponse(
                $request,
                "We couldn't submit your city grant because of an unexpected error. Try again, or contact the economics team with reference {$referenceId}."
            );
        }

        return redirect()
            ->route('grants.city')
            ->with([
                'alert-message' => 'City grant requested! Please give us up to 24 hours to review your request.',
                'alert-type' => 'success',
            ]);
    }

    private function failureMessage(CityGrantFailureReason $reason): string
    {
        return match ($reason) {
            CityGrantFailureReason::Eligibility => 'You are not currently eligible for this city grant. Review the eligibility requirements shown on this page, correct any unmet items, and try again.',
            CityGrantFailureReason::Cooldown => 'Your city or project purchase cooldown is still active. Wait until the required turns have passed, refresh your nation data, and try again.',
            CityGrantFailureReason::PendingRequest => 'You already have a city grant request awaiting review. Wait for a decision before submitting another request.',
            CityGrantFailureReason::MissingAudit => 'A current nation audit is required for this city grant. Complete or refresh your audit, then try again.',
            CityGrantFailureReason::InsufficientData => 'There is not enough current nation data to verify this request. Refresh your nation data and try again; contact the economics team if the problem continues.',
            CityGrantFailureReason::PolicyLimit => 'This city grant is outside the currently available program limits or has already been used. Review the available grant tier shown on this page or contact the economics team.',
            CityGrantFailureReason::ExternalOutage => 'City-cost data is temporarily unavailable, so no request was submitted. Please try again later.',
        };
    }

    private function failureResponse(StoreCityGrantRequest $request, string $message): RedirectResponse
    {
        return redirect()
            ->route('grants.city')
            ->withInput($request->safe()->only('account_id'))
            ->withErrors(['city_grant' => $message])
            ->with([
                'alert-message' => $message,
                'alert-type' => 'error',
            ]);
    }
}
