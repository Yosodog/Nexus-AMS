<?php

namespace App\Services;

use App\Models\Account;
use App\Models\GrowthCircleDistribution;
use App\Models\GrowthCircleEnrollment;
use App\Models\Nation;
use App\Notifications\Channels\DiscordQueueChannel;
use App\Notifications\GrowthCircleAbuseSuspendedNotification;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

class GrowthCircleService
{
    /**
     * Per-city per-hour consumption rates used for abuse detection.
     * These are intentional overestimates to reduce false positives.
     */
    private const FOOD_CONSUMPTION_PER_CITY_PER_HOUR = 200.0;

    private const URANIUM_CONSUMPTION_PER_CITY_PER_HOUR = 1.5;

    /**
     * Enroll a nation in Growth Circles.
     * Stores their current tax bracket, creates the enrollment record,
     * and assigns the 100% Growth Circle tax bracket.
     *
     * @throws ValidationException
     */
    public function enroll(Nation $nation): GrowthCircleEnrollment
    {
        if (GrowthCircleEnrollment::query()->where('nation_id', $nation->id)->exists()) {
            throw ValidationException::withMessages([
                'growth_circle' => 'You are already enrolled in Growth Circles.',
            ]);
        }

        try {
            $enrollment = GrowthCircleEnrollment::query()->create([
                'nation_id' => $nation->id,
                'previous_tax_id' => $nation->tax_id,
                'suspended' => false,
                'suspended_at' => null,
                'suspended_reason' => null,
                'enrolled_at' => now(),
            ]);
        } catch (QueryException $e) {
            throw ValidationException::withMessages([
                'growth_circle' => 'You are already enrolled in Growth Circles.',
            ]);
        }

        $taxId = SettingService::getGrowthCircleTaxId();

        if ($taxId > 0) {
            $mutation = new TaxBracketService;
            $mutation->id = $taxId;
            $mutation->target_id = $nation->id;
            $mutation->send();
        }

        return $enrollment;
    }

    /**
     * Remove a nation from Growth Circles (admin only).
     * Restores their previous tax bracket and deletes the enrollment.
     */
    public function remove(Nation $nation): void
    {
        $enrollment = GrowthCircleEnrollment::where('nation_id', $nation->id)->first();

        if (! $enrollment) {
            return;
        }

        $bracketId = ($enrollment->previous_tax_id !== null && $enrollment->previous_tax_id > 0)
            ? $enrollment->previous_tax_id
            : SettingService::getGrowthCircleFallbackTaxId();

        if ($bracketId > 0) {
            $mutation = new TaxBracketService;
            $mutation->id = $bracketId;
            $mutation->target_id = $nation->id;
            $mutation->send();
        }

        $enrollment->delete();
    }

    /**
     * Clear a suspension on an enrollment, re-activating distributions.
     */
    public function clearSuspension(GrowthCircleEnrollment $enrollment): void
    {
        $enrollment->update([
            'suspended' => false,
            'suspended_at' => null,
            'suspended_reason' => null,
        ]);
    }

    /**
     * Run the distribution pass: top up food and uranium for all active enrollments.
     *
     * @return array{processed: int, sent: int, skipped: int}
     */
    public function distribute(): array
    {
        $sourceAccountId = SettingService::getGrowthCircleSourceAccountId();
        $foodPerCity = SettingService::getGrowthCircleFoodPerCity();
        $uraniumPerCity = SettingService::getGrowthCircleUraniumPerCity();

        $summary = ['processed' => 0, 'sent' => 0, 'skipped' => 0];

        GrowthCircleEnrollment::query()
            ->where('suspended', false)
            ->with('nation')
            ->chunkById(100, function ($enrollments) use (
                $sourceAccountId,
                $foodPerCity,
                $uraniumPerCity,
                &$summary
            ): void {
                foreach ($enrollments as $enrollment) {
                    $summary['processed']++;

                    $nation = $enrollment->nation;

                    if (! $nation) {
                        $summary['skipped']++;

                        continue;
                    }

                    $this->distributeToNation(
                        $nation,
                        $sourceAccountId,
                        $foodPerCity,
                        $uraniumPerCity,
                        $summary
                    );
                }
            });

        return $summary;
    }

    /**
     * Run the abuse detection pass after all distributions are complete.
     * Suspends nations whose resource levels are suspiciously low.
     */
    public function runAbuseDetection(): void
    {
        $windowStart = now()->subHours(24);

        GrowthCircleEnrollment::query()
            ->where('suspended', false)
            ->with('nation')
            ->chunkById(100, function ($enrollments) use ($windowStart): void {
                foreach ($enrollments as $enrollment) {
                    $nation = $enrollment->nation;

                    if (! $nation) {
                        continue;
                    }

                    $resources = $nation->resources;

                    if (! $resources) {
                        continue;
                    }

                    $totals = GrowthCircleDistribution::query()
                        ->where('nation_id', $nation->id)
                        ->where('created_at', '>=', $windowStart)
                        ->selectRaw('SUM(food_sent) as total_food, SUM(uranium_sent) as total_uranium')
                        ->first();

                    if (! $totals) {
                        continue;
                    }

                    $cityCount = (int) $nation->num_cities;
                    $hoursInWindow = 24;

                    $estimatedFoodConsumed = $cityCount * self::FOOD_CONSUMPTION_PER_CITY_PER_HOUR * $hoursInWindow;
                    $estimatedUraniumConsumed = $cityCount * self::URANIUM_CONSUMPTION_PER_CITY_PER_HOUR * $hoursInWindow;

                    $expectedFoodFloor = (float) $totals->total_food - $estimatedFoodConsumed;
                    $expectedUraniumFloor = (float) $totals->total_uranium - $estimatedUraniumConsumed;

                    $currentFood = (float) ($resources->food ?? 0);
                    $currentUranium = (float) ($resources->uranium ?? 0);

                    $foodAbuse = $expectedFoodFloor > 0 && $currentFood < ($expectedFoodFloor * 0.20);
                    $uraniumAbuse = $expectedUraniumFloor > 0 && $currentUranium < ($expectedUraniumFloor * 0.20);

                    if ($foodAbuse || $uraniumAbuse) {
                        $this->suspendForAbuse($enrollment, $nation);
                    }
                }
            });
    }

    private function distributeToNation(
        Nation $nation,
        int $sourceAccountId,
        int $foodPerCity,
        int $uraniumPerCity,
        array &$summary
    ): void {
        $resources = $nation->resources;
        $requiresRefresh = is_null($resources)
            || ($resources->updated_at?->lt(now()->subHours(3)) ?? true);

        if ($requiresRefresh) {
            try {
                $graphQLNation = NationQueryService::getNationById($nation->id);
                Nation::updateFromAPI($graphQLNation);
                $nation->refresh();
                $resources = $nation->resources;
            } catch (\Throwable $e) {
                Log::warning("GrowthCircles: resource refresh failed for nation {$nation->id}: {$e->getMessage()}");
                $summary['skipped']++;

                return;
            }
        }

        if (! $resources) {
            $summary['skipped']++;

            return;
        }

        $cityCount = (int) $nation->num_cities;
        $foodTarget = $cityCount * $foodPerCity;
        $uraniumTarget = $cityCount * $uraniumPerCity;

        $currentFood = (float) ($resources->food ?? 0);
        $currentUranium = (float) ($resources->uranium ?? 0);

        $foodToSend = max(0.0, $foodTarget - $currentFood);
        $uraniumToSend = max(0.0, $uraniumTarget - $currentUranium);

        $sent = false;

        if ($foodToSend > 0 || $uraniumToSend > 0) {
            $transaction = null;
            $lockedAccount = null;

            DB::transaction(function () use (
                $nation,
                $sourceAccountId,
                &$foodToSend,
                &$uraniumToSend,
                &$transaction,
                &$lockedAccount
            ): void {
                $lockedAccount = Account::query()->lockForUpdate()->find($sourceAccountId);

                if (! $lockedAccount) {
                    Log::warning("GrowthCircles: source account {$sourceAccountId} not found.");

                    return;
                }

                $foodToSend = min($foodToSend, (float) floor($lockedAccount->food));
                $uraniumToSend = min($uraniumToSend, (float) floor($lockedAccount->uranium));

                if ($foodToSend <= 0 && $uraniumToSend <= 0) {
                    Log::warning("GrowthCircles: source account {$sourceAccountId} has insufficient food/uranium for nation {$nation->id}.");

                    return;
                }

                $lockedAccount->food -= $foodToSend;
                $lockedAccount->uranium -= $uraniumToSend;
                $lockedAccount->save();

                $resources = [];

                if ($foodToSend > 0) {
                    $resources['food'] = $foodToSend;
                }

                if ($uraniumToSend > 0) {
                    $resources['uranium'] = $uraniumToSend;
                }

                $transaction = TransactionService::createTransaction(
                    resources: $resources,
                    nation_id: $nation->id,
                    fromAccountId: $lockedAccount->id,
                    transactionType: 'withdrawal',
                    isPending: true,
                    requiresAdminApproval: false,
                    note: 'Growth Circle distribution',
                );
            });

            if ($transaction && $lockedAccount) {
                AccountService::dispatchWithdrawal($transaction, $lockedAccount);
                $sent = true;
            }
        }

        GrowthCircleDistribution::create([
            'nation_id' => $nation->id,
            'food_sent' => $foodToSend,
            'uranium_sent' => $uraniumToSend,
            'food_level_before' => $currentFood,
            'uranium_level_before' => $currentUranium,
            'city_count' => $cityCount,
        ]);

        if ($sent) {
            $summary['sent']++;
        }
    }

    private function suspendForAbuse(GrowthCircleEnrollment $enrollment, Nation $nation): void
    {
        $enrollment->update([
            'suspended' => true,
            'suspended_at' => now(),
            'suspended_reason' => 'Resource levels significantly below expected after distributions. Possible selling detected.',
        ]);

        $channelId = SettingService::getGrowthCircleDiscordChannelId();

        if (empty($channelId)) {
            Log::warning("GrowthCircles: abuse detected for nation {$nation->id} but no Discord channel configured.");

            return;
        }

        Notification::route(DiscordQueueChannel::class, 'discord-bot')
            ->notify(new GrowthCircleAbuseSuspendedNotification($channelId, $nation));
    }
}
