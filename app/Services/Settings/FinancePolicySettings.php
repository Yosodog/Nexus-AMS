<?php

namespace App\Services\Settings;

use Illuminate\Support\Carbon;

class FinancePolicySettings
{
    private const LOTTERY_SETTINGS_KEY = 'lottery_configuration';

    public const DEFAULT_LOTTERY_TICKET_PRICE_CENTS = 5000000;

    public const DEFAULT_LOTTERY_JACKPOT_BASIS_POINTS = 9000;

    public const DEFAULT_LOTTERY_MAX_TICKETS_PER_PURCHASE = 100;

    public const DEFAULT_LOTTERY_MAX_TICKETS_PER_NATION = 10000;

    public const MAX_LOTTERY_TICKET_PRICE_CENTS = 1000000000;

    public const MAX_LOTTERY_TICKETS_PER_PURCHASE = 500;

    public const MAX_LOTTERY_TICKETS_PER_NATION = 10000;

    public function __construct(private readonly SettingValueStore $settings) {}

    /**
     * @return array{
     *     sales_enabled: bool,
     *     ticket_price_cents: int,
     *     jackpot_basis_points: int,
     *     max_tickets_per_purchase: int,
     *     max_tickets_per_nation: int
     * }
     */
    public function getLotterySettings(): array
    {
        $stored = $this->settings->get(self::LOTTERY_SETTINGS_KEY);
        $decoded = is_string($stored) ? json_decode($stored, true) : null;

        return $this->normalizeLotterySettings(is_array($decoded) ? $decoded : []);
    }

    /**
     * @param  array{
     *     sales_enabled: bool,
     *     ticket_price_cents: int,
     *     jackpot_basis_points: int,
     *     max_tickets_per_purchase: int,
     *     max_tickets_per_nation: int
     * }  $settings
     */
    public function setLotterySettings(array $settings): void
    {
        $this->settings->set(
            self::LOTTERY_SETTINGS_KEY,
            json_encode($this->normalizeLotterySettings($settings), JSON_THROW_ON_ERROR),
        );
    }

    public function isWarAidEnabled(): bool
    {
        $value = $this->settings->get('war_aid_enabled');

        if (is_null($value)) {
            $this->settings->set('war_aid_enabled', 0);

            return false;
        }

        return (bool) $value;
    }

    public function setWarAidEnabled(bool $enabled): void
    {
        $this->settings->set('war_aid_enabled', $enabled ? 1 : 0);
    }

    public function isRebuildingEnabled(): bool
    {
        $value = $this->settings->get('rebuilding_enabled');

        if (is_null($value)) {
            $this->setRebuildingEnabled(false);

            return false;
        }

        return (bool) $value;
    }

    public function setRebuildingEnabled(bool $enabled): void
    {
        $this->settings->set('rebuilding_enabled', $enabled ? 1 : 0);
    }

    public function getRebuildingCycleId(): int
    {
        $value = $this->settings->get('rebuilding_cycle_id');

        if (is_null($value)) {
            $this->settings->set('rebuilding_cycle_id', 1);

            return 1;
        }

        return max(1, (int) $value);
    }

    public function setRebuildingCycleId(int $cycleId): void
    {
        $this->settings->set('rebuilding_cycle_id', max(1, $cycleId));
    }

    public function incrementRebuildingCycleId(): int
    {
        $next = $this->getRebuildingCycleId() + 1;
        $this->setRebuildingCycleId($next);

        return $next;
    }

    public function getRebuildingLastEstimateRefreshAt(): ?Carbon
    {
        $value = $this->settings->get('rebuilding_last_estimate_refresh_at');

        if (! is_string($value) || $value === '') {
            return null;
        }

        return Carbon::parse($value);
    }

    public function setRebuildingLastEstimateRefreshAt(Carbon $timestamp): void
    {
        $this->settings->set('rebuilding_last_estimate_refresh_at', $timestamp->toIso8601String());
    }

    public function isAutoWithdrawEnabled(): bool
    {
        $value = $this->settings->get('auto_withdraw_enabled');

        if (is_null($value)) {
            $this->setAutoWithdrawEnabled(true);

            return true;
        }

        return (bool) $value;
    }

    public function setAutoWithdrawEnabled(bool $enabled): void
    {
        $this->settings->set('auto_withdraw_enabled', $enabled ? 1 : 0);
    }

    public function isLoanPaymentsEnabled(): bool
    {
        $value = $this->settings->get('loan_payments_enabled');

        if (is_null($value)) {
            $this->setLoanPaymentsEnabled(true);

            return true;
        }

        return (bool) $value;
    }

    public function setLoanPaymentsEnabled(bool $enabled): void
    {
        $this->settings->set('loan_payments_enabled', $enabled ? 1 : 0);
    }

    public function isLoanApplicationsEnabled(): bool
    {
        $value = $this->settings->get('loan_applications_enabled');

        if (is_null($value)) {
            $this->setLoanApplicationsEnabled(true);

            return true;
        }

        return (bool) $value;
    }

    public function setLoanApplicationsEnabled(bool $enabled): void
    {
        $this->settings->set('loan_applications_enabled', $enabled ? 1 : 0);
    }

    public function getDefaultLoanInterestRate(): float
    {
        $value = $this->settings->get('loan_default_interest_rate');

        if (is_null($value)) {
            $this->setDefaultLoanInterestRate(0.0);

            return 0.0;
        }

        return max(0.0, (float) $value);
    }

    public function setDefaultLoanInterestRate(float $rate): void
    {
        $this->settings->set('loan_default_interest_rate', max(0.0, $rate));
    }

    public function isGrantApprovalsEnabled(): bool
    {
        $value = $this->settings->get('grant_approvals_enabled');

        if (is_null($value)) {
            $this->setGrantApprovalsEnabled(true);

            return true;
        }

        return (bool) $value;
    }

    public function setGrantApprovalsEnabled(bool $enabled): void
    {
        $this->settings->set('grant_approvals_enabled', $enabled ? 1 : 0);
    }

    public function getLoanPaymentsPausedAt(): ?Carbon
    {
        $value = $this->settings->get('loan_payments_paused_at');

        if (! is_string($value) || $value === '') {
            return null;
        }

        return Carbon::parse($value);
    }

    public function setLoanPaymentsPausedAt(?Carbon $timestamp): void
    {
        $this->settings->set('loan_payments_paused_at', $timestamp ? $timestamp->toIso8601String() : '');
    }

    public function getDirectDepositId(): int
    {
        $value = $this->settings->get('dd_tax_id');

        if (is_null($value)) {
            $this->setDirectDepositId(0);

            return 0;
        }

        return (int) $value;
    }

    public function setDirectDepositId(int $taxId): void
    {
        $this->settings->set('dd_tax_id', $taxId);
    }

    public function getDirectDepositFallbackId(): int
    {
        $value = $this->settings->get('dd_fallback_tax_id');

        if (is_null($value)) {
            $this->setDirectDepositFallbackId(0);

            return 0;
        }

        return (int) $value;
    }

    public function setDirectDepositFallbackId(int $taxId): void
    {
        $this->settings->set('dd_fallback_tax_id', $taxId);
    }

    public function isDirectDepositEnabled(): bool
    {
        return $this->getDirectDepositId() > 0;
    }

    public function getGrowthCirclesTaxId(): int
    {
        $value = $this->settings->get('growth_circles_tax_id');

        if (is_null($value)) {
            $this->setGrowthCirclesTaxId(0);

            return 0;
        }

        return (int) $value;
    }

    public function setGrowthCirclesTaxId(int $taxId): void
    {
        $this->settings->set('growth_circles_tax_id', $taxId);
    }

    public function getGrowthCirclesFallbackTaxId(): int
    {
        $value = $this->settings->get('growth_circles_fallback_tax_id');

        if (is_null($value)) {
            $this->setGrowthCirclesFallbackTaxId(0);

            return 0;
        }

        return (int) $value;
    }

    public function setGrowthCirclesFallbackTaxId(int $taxId): void
    {
        $this->settings->set('growth_circles_fallback_tax_id', $taxId);
    }

    public function isGrowthCirclesEnabled(): bool
    {
        return $this->getGrowthCirclesTaxId() > 0;
    }

    public function getWithdrawMaxDailyCount(): int
    {
        $value = $this->settings->get('withdraw_max_daily_count');

        if (is_null($value)) {
            $this->setWithdrawMaxDailyCount(0);

            return 0;
        }

        return (int) $value;
    }

    public function setWithdrawMaxDailyCount(int $count): void
    {
        $this->settings->set('withdraw_max_daily_count', max(0, $count));
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array{
     *     sales_enabled: bool,
     *     ticket_price_cents: int,
     *     jackpot_basis_points: int,
     *     max_tickets_per_purchase: int,
     *     max_tickets_per_nation: int
     * }
     */
    private function normalizeLotterySettings(array $settings): array
    {
        $maxTicketsPerNation = min(
            self::MAX_LOTTERY_TICKETS_PER_NATION,
            max(1, (int) ($settings['max_tickets_per_nation'] ?? self::DEFAULT_LOTTERY_MAX_TICKETS_PER_NATION)),
        );
        $maxTicketsPerPurchase = min(
            self::MAX_LOTTERY_TICKETS_PER_PURCHASE,
            $maxTicketsPerNation,
            max(1, (int) ($settings['max_tickets_per_purchase'] ?? self::DEFAULT_LOTTERY_MAX_TICKETS_PER_PURCHASE)),
        );
        $salesEnabled = filter_var(
            $settings['sales_enabled'] ?? true,
            FILTER_VALIDATE_BOOL,
            FILTER_NULL_ON_FAILURE,
        );

        return [
            'sales_enabled' => $salesEnabled ?? true,
            'ticket_price_cents' => min(
                self::MAX_LOTTERY_TICKET_PRICE_CENTS,
                max(100, (int) ($settings['ticket_price_cents'] ?? self::DEFAULT_LOTTERY_TICKET_PRICE_CENTS)),
            ),
            'jackpot_basis_points' => min(
                10000,
                max(0, (int) ($settings['jackpot_basis_points'] ?? self::DEFAULT_LOTTERY_JACKPOT_BASIS_POINTS)),
            ),
            'max_tickets_per_purchase' => $maxTicketsPerPurchase,
            'max_tickets_per_nation' => $maxTicketsPerNation,
        ];
    }
}
