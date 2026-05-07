<?php

namespace App\Services;

use App\DataTransferObjects\AllianceFinanceData;
use App\Enums\AlliancePositionEnum;
use App\Events\AllianceExpenseOccurred;
use App\Exceptions\UserErrorException;
use App\Models\Account;
use App\Models\DirectDepositEnrollment;
use App\Models\GrowthCircleDistribution;
use App\Models\GrowthCircleEnrollment;
use App\Models\Nation;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
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

    /**
     * Run the daily distribution loop for every enrolled nation.
     * Returns counts of distributed / skipped / failed members for logging.
     *
     * @return array{distributed: int, skipped: int, failed: int}
     */
    public function runDailyDistribution(?string $cycleDate = null): array
    {
        $cycleDate ??= Carbon::now('UTC')->toDateString();
        $counts = ['distributed' => 0, 'skipped' => 0, 'failed' => 0];

        GrowthCircleEnrollment::query()
            ->with(['nation', 'account'])
            ->chunkById(200, function ($enrollments) use ($cycleDate, &$counts): void {
                foreach ($enrollments as $enrollment) {
                    $outcome = $this->distributeOne($enrollment, $cycleDate);
                    $counts[$outcome]++;
                }
            });

        return $counts;
    }

    /**
     * Process one enrollment for one cycle. Returns the outcome bucket name
     * so the caller can tally counts.
     *
     * @return 'distributed'|'skipped'|'failed'
     */
    protected function distributeOne(GrowthCircleEnrollment $enrollment, string $cycleDate): string
    {
        $nation = $enrollment->nation;
        if (! $nation) {
            Log::warning('growth_circles.skip', [
                'reason' => 'nation_missing',
                'enrollment_id' => $enrollment->id,
            ]);

            return 'skipped';
        }

        try {
            return DB::transaction(function () use ($enrollment, $nation, $cycleDate): string {
                $eligibility = $this->evaluateEligibility($nation);
                if (! $eligibility['eligible']) {
                    Log::info('growth_circles.skip', [
                        'nation_id' => $nation->id,
                        'reason' => $eligibility['reason'],
                        'cycle_date' => $cycleDate,
                    ]);

                    return 'skipped';
                }

                $shortfall = app(NationProfitabilityService::class)->getDailyResourceShortfall($nation);
                if ($shortfall === null) {
                    Log::warning('growth_circles.no_snapshot', [
                        'nation_id' => $nation->id,
                        'cycle_date' => $cycleDate,
                    ]);

                    return 'skipped';
                }

                if ($shortfall['food'] <= 0.0 && $shortfall['uranium'] <= 0.0) {
                    Log::info('growth_circles.no_shortfall', [
                        'nation_id' => $nation->id,
                        'cycle_date' => $cycleDate,
                    ]);

                    return 'skipped';
                }

                $account = Account::query()
                    ->whereKey($enrollment->account_id)
                    ->lockForUpdate()
                    ->first();

                if (! $account) {
                    Log::error('growth_circles.account_missing', [
                        'nation_id' => $nation->id,
                        'enrollment_id' => $enrollment->id,
                    ]);

                    return 'skipped';
                }

                // The account credit is performed here, NOT by the
                // AllianceExpenseOccurred listener. The listener
                // (RecordAllianceExpense) writes only an
                // AllianceFinanceEntry for reporting; it does not touch
                // Account balances. This matches DirectDepositService::process.
                $account->food += $shortfall['food'];
                $account->uranium += $shortfall['uranium'];
                $account->save();

                $distribution = GrowthCircleDistribution::query()->create([
                    'nation_id' => $nation->id,
                    'account_id' => $account->id,
                    'enrollment_id' => $enrollment->id,
                    'food' => $shortfall['food'],
                    'uranium' => $shortfall['uranium'],
                    'cycle_date' => $cycleDate,
                ]);

                event(new AllianceExpenseOccurred(
                    AllianceFinanceData::forGrowthCircleDistribution(
                        $nation,
                        $account,
                        $distribution,
                        $shortfall['food'],
                        $shortfall['uranium'],
                    )->toArray()
                ));

                return 'distributed';
            });
        } catch (UniqueConstraintViolationException) {
            return 'skipped';
        } catch (Throwable $e) {
            Log::error('growth_circles.distribute.failed', [
                'nation_id' => $enrollment->nation_id,
                'enrollment_id' => $enrollment->id,
                'message' => $e->getMessage(),
            ]);

            return 'failed';
        }
    }
}
