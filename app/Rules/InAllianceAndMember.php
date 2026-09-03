<?php

namespace App\Rules;

use App\Exceptions\PWEntityDoesNotExist;
use App\GraphQL\Models\Nation as GraphQLNation;
use App\Models\Nation;
use App\Services\AllianceMembershipService;
use App\Services\NationQueryService;
use App\Services\RuntimeCapabilities;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Log;
use Illuminate\Translation\PotentiallyTranslatedString;
use Throwable;

class InAllianceAndMember implements ValidationRule
{
    public function __construct(
        private readonly ?AllianceMembershipService $membershipService = null,
        private readonly ?RuntimeCapabilities $runtimeCapabilities = null,
    ) {}

    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < 1) {
            $fail('That nation ID is invalid.');

            return;
        }

        $nationId = (int) $value;
        $membershipService = $this->membershipService ?? app(AllianceMembershipService::class);

        try {
            $nation = $this->fetchLiveNation($nationId);
            $liveAllianceId = $nation->alliance_id;
            $liveAlliancePosition = $nation->alliance_position;

            if ($this->capabilities()->writesPublicWorld()) {
                $storedNation = Nation::updateFromAPI($nation);
                $liveAllianceId = $storedNation->alliance_id;
                $liveAlliancePosition = $storedNation->alliance_position;
            }
        } catch (PWEntityDoesNotExist) {
            $fail('That nation does not exist');

            return;
        } catch (Throwable $exception) {
            Log::warning('Live nation membership validation failed during registration.', [
                'nation_id' => $nationId,
                'exception' => $exception::class,
            ]);
            $fail('Unable to verify nation membership right now. Please try again.');

            return;
        }

        if (
            ! $membershipService->contains($liveAllianceId)
            || strtoupper((string) $liveAlliancePosition) === 'APPLICANT'
        ) {
            $fail('You are either not in the alliance or you are still an applicant.');
        }
    }

    protected function fetchLiveNation(int $nationId): GraphQLNation
    {
        return NationQueryService::getNationById($nationId);
    }

    private function capabilities(): RuntimeCapabilities
    {
        return $this->runtimeCapabilities ?? app(RuntimeCapabilities::class);
    }
}
