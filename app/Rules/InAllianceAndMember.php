<?php

namespace App\Rules;

use App\Exceptions\PWEntityDoesNotExist;
use App\Models\Nations;
use App\Services\NationQueryService;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class InAllianceAndMember implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  \Closure(string, ?string=): \Illuminate\Translation\PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // First let's just see if the nation is in our database
        try {
            $nation = Nations::getNationById($value);

            if ($nation->alliance_id == env("PW_ALLIANCE_ID"))
            {
                if ($nation->alliance_position == "APPLICANT")
                    $fail("You are either not in the alliance or you are still an applicant.");

                return;
            }
        }
        catch (\Exception $e) {} // We will not do anything with the exception. If they're not in our DB, then we will just move down here naturally
        // Additionally, if they are in the DB but they are not in the alliance, we need to query to see if our database is just out of date.

        try {
            $nation = NationQueryService::getNationById($value);
        } catch (PWEntityDoesNotExist) {
            $fail("That nation does not exist");

            return;
        }

        Nations::updateFromAPI($nation); // Obviously we're out of date so just go ahead and save/update the data now

        if ($nation->alliance_id == env("PW_ALLIANCE_ID"))
        {
            if ($nation->alliance_position == "APPLICANT")
                $fail("You are either not in the alliance or you are still an applicant.");

            return;
        }

        $fail("You are either not in the alliance or you are still an applicant.");
    }
}
