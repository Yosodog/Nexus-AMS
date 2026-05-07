<?php

namespace App\Services;

use App\Enums\AlliancePositionEnum;
use App\Exceptions\UserErrorException;
use App\Models\Account;
use App\Models\DirectDepositEnrollment;
use App\Models\GrowthCircleEnrollment;
use App\Models\Nation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class GrowthCircleService
{
    public function __construct(
        protected AllianceMembershipService $membershipService,
    ) {}

    /**
     * Evaluate the five eligibility gates for Growth Circles.
     *
     * Both enrollment and per-cycle distribution use this method, so
     * a member who later loses eligibility is paused (not auto-disenrolled).
     *
     * @return array{eligible: bool, reason: ?string}
     */
    public function evaluateEligibility(Nation $nation): array
    {
        if (! $this->membershipService->contains((int) $nation->alliance_id)) {
            return ['eligible' => false, 'reason' => 'Nation is not in the alliance group.'];
        }

        if (($nation->alliance_position ?? null) === AlliancePositionEnum::APPLICANT->value) {
            return ['eligible' => false, 'reason' => 'Applicants are not eligible for Growth Circles.'];
        }

        if ((int) ($nation->vacation_mode_turns ?? 0) > 0) {
            return ['eligible' => false, 'reason' => 'Not available while in vacation mode.'];
        }

        if (strtolower((string) ($nation->color ?? '')) === 'beige') {
            return ['eligible' => false, 'reason' => 'Not available while in beige.'];
        }

        if ((int) ($nation->num_cities ?? 0) <= 0) {
            return ['eligible' => false, 'reason' => 'Nation has no cities.'];
        }

        return ['eligible' => true, 'reason' => null];
    }

    public function enroll(Nation $nation, Account $account): void
    {
        $eligibility = $this->evaluateEligibility($nation);
        if (! $eligibility['eligible']) {
            throw new UserErrorException($eligibility['reason']);
        }

        if ((int) $account->nation_id !== (int) $nation->id) {
            throw new UserErrorException('Selected account does not belong to your nation.');
        }

        $taxId = SettingService::getGrowthCirclesTaxId();
        if ($taxId <= 0) {
            throw new UserErrorException('Growth Circles is not configured. Contact an admin.');
        }

        if ($ddEnrollment = DirectDepositEnrollment::query()->where('nation_id', $nation->id)->first()) {
            $previousTaxId = (int) $ddEnrollment->previous_tax_id;
            app(DirectDepositService::class)->disenroll($nation);
            $auditAction = 'switched_from_dd';
        } elseif ($existing = GrowthCircleEnrollment::query()->where('nation_id', $nation->id)->first()) {
            $previousTaxId = (int) $existing->previous_tax_id;
            $auditAction = 'enrolled';
        } else {
            $previousTaxId = (int) $nation->tax_id;
            $auditAction = 'enrolled';
        }

        $enrollment = DB::transaction(function () use ($nation, $account, $previousTaxId): GrowthCircleEnrollment {
            return GrowthCircleEnrollment::query()->updateOrCreate(
                ['nation_id' => $nation->id],
                [
                    'account_id' => $account->id,
                    'previous_tax_id' => $previousTaxId,
                    'enrolled_at' => now(),
                ],
            );
        });

        $mutation = new TaxBracketService;
        $mutation->id = $taxId;
        $mutation->target_id = (int) $nation->id;
        $mutation->send();

        app(AuditLogger::class)->recordAfterCommit(
            category: 'growth_circles',
            action: $auditAction,
            subject: $enrollment,
            context: [
                'data' => [
                    'nation_id' => $nation->id,
                    'account_id' => $account->id,
                    'previous_tax_id' => $previousTaxId,
                    'new_tax_id' => $taxId,
                ],
            ],
            message: $auditAction === 'switched_from_dd'
                ? "Switched nation {$nation->nation_name} from DirectDeposit to Growth Circles."
                : "Enrolled nation {$nation->nation_name} in Growth Circles.",
        );
    }

    public function disenroll(Nation $nation): void
    {
        $enrollment = GrowthCircleEnrollment::query()->where('nation_id', $nation->id)->first();
        if (! $enrollment) {
            return;
        }

        $targetTaxId = (int) $enrollment->previous_tax_id;
        $fallbackTaxId = SettingService::getGrowthCirclesFallbackTaxId();

        try {
            $mutation = new TaxBracketService;
            $mutation->id = $targetTaxId > 0 ? $targetTaxId : $fallbackTaxId;
            $mutation->target_id = (int) $nation->id;
            $mutation->send();
        } catch (Throwable $e) {
            Log::warning(
                "GrowthCircles: failed to assign previous tax ID {$targetTaxId} for nation {$nation->id}, retrying with fallback {$fallbackTaxId}.",
                ['exception' => $e->getMessage()],
            );
            try {
                $fallbackMutation = new TaxBracketService;
                $fallbackMutation->id = $fallbackTaxId;
                $fallbackMutation->target_id = (int) $nation->id;
                $fallbackMutation->send();
            } catch (Throwable $fallbackException) {
                Log::error(
                    "GrowthCircles: fallback tax-id assign also failed for nation {$nation->id}; deleting enrollment row anyway.",
                    ['exception' => $fallbackException->getMessage()],
                );
            }
        }

        $enrollment->delete();

        app(AuditLogger::class)->recordAfterCommit(
            category: 'growth_circles',
            action: 'disenrolled',
            subject: $enrollment,
            context: [
                'data' => [
                    'nation_id' => $nation->id,
                    'restored_tax_id' => $targetTaxId,
                ],
            ],
            message: "Disenrolled nation {$nation->nation_name} from Growth Circles.",
        );
    }
}
