<?php

namespace App\Services;

use App\Exceptions\PWEntityDoesNotExist;
use App\GraphQL\Models\Nation as GraphQlNation;
use App\Models\Nation;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class AuthoritativeNationMembershipService
{
    public function __construct(
        private readonly AllianceMembershipService $membershipService,
        private readonly ?RuntimeCapabilities $runtimeCapabilities = null,
    ) {}

    /**
     * @throws ValidationException
     */
    public function validate(int $nationId): void
    {
        try {
            $nation = $this->fetchNation($nationId);
            $liveAllianceId = $nation->alliance_id;
            $liveAlliancePosition = $nation->alliance_position;

            if ($this->capabilities()->writesPublicWorld()) {
                $storedNation = Nation::updateFromAPI($nation);
                $liveAllianceId = $storedNation->alliance_id;
                $liveAlliancePosition = $storedNation->alliance_position;
            }
        } catch (PWEntityDoesNotExist) {
            throw ValidationException::withMessages([
                'alliance' => 'The recipient nation no longer exists.',
            ]);
        } catch (Throwable $exception) {
            Log::warning('Unable to validate authoritative nation membership before disbursement.', [
                'nation_id' => $nationId,
                'error' => $exception->getMessage(),
            ]);

            throw ValidationException::withMessages([
                'alliance' => 'Unable to verify current alliance membership. Please try again.',
            ]);
        }

        if (! $this->membershipService->contains($liveAllianceId)) {
            throw ValidationException::withMessages([
                'alliance' => 'The recipient is no longer a member of the required alliance.',
            ]);
        }

        if ($liveAlliancePosition === 'APPLICANT') {
            throw ValidationException::withMessages([
                'alliance' => 'Applicants are not eligible for financial aid.',
            ]);
        }
    }

    protected function fetchNation(int $nationId): GraphQlNation
    {
        return NationQueryService::getNationById($nationId);
    }

    private function capabilities(): RuntimeCapabilities
    {
        return $this->runtimeCapabilities ?? app(RuntimeCapabilities::class);
    }
}
