<?php

namespace App\Rules;

use App\Services\GrantRequirementService;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

class ValidGrantRequirementTree implements ValidationRule
{
    public function __construct(private readonly GrantRequirementService $grantRequirementService) {}

    /**
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $inspection = $this->grantRequirementService->inspect($value);

        foreach ($inspection['errors'] as $message) {
            $fail($message);
        }
    }
}
