<?php

namespace App\Rules;

use App\Exceptions\PWEntityDoesNotExist;
use App\Models\Nation;
use App\Services\AllianceMembershipService;
use App\Services\NationQueryService;
use Closure;
use Exception;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

class InAllianceAndMember implements ValidationRule
{
    public function __construct(private readonly ?AllianceMembershipService $membershipService = null)
    {
    }

    /**
     * Run the validation rule.
     *
     * @param Closure(string, ?string=): PotentiallyTranslatedString $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $membershipService = $this->membershipService ?? app(AllianceMembershipService::class);

        try {
            $nation = Nation::getNationById($value);

            if ($membershipService->contains($nation->alliance_id)) {
                if ($nation->alliance_position === 'APPLICANT') {
                    $fail('You are either not in the alliance or you are still an applicant.');
                }

                return;
            }
        } catch (Exception $exception) {
            // Ignore and fall back to the live API check below.
        }

        try {
            $nation = NationQueryService::getNationById($value);
        } catch (PWEntityDoesNotExist) {
            $fail('That nation does not exist');

            return;
        }

        Nation::updateFromAPI($nation);

        if ($membershipService->contains($nation->alliance_id)) {
            if ($nation->alliance_position === 'APPLICANT') {
                $fail('You are either not in the alliance or you are still an applicant.');
            }

            return;
        }

        $fail('You are either not in the alliance or you are still an applicant.');
    }
}
