<?php

namespace App\Services;

use App\Models\Offshore;
use App\Services\Settings\FinancePolicySettings;

class AllianceTaxProgramService
{
    /**
     * @var array<int, array{
     *     direct_deposit_tax_id: int,
     *     direct_deposit_fallback_tax_id: int,
     *     growth_circles_tax_id: int,
     *     growth_circles_fallback_tax_id: int
     * }>
     */
    private array $configurations = [];

    public function __construct(
        private readonly AllianceMembershipService $membershipService,
        private readonly FinancePolicySettings $financePolicySettings,
    ) {}

    public function getDirectDepositTaxId(int $allianceId): int
    {
        return $this->configurationForAlliance($allianceId)['direct_deposit_tax_id'];
    }

    public function getDirectDepositFallbackTaxId(int $allianceId): int
    {
        return $this->configurationForAlliance($allianceId)['direct_deposit_fallback_tax_id'];
    }

    public function isDirectDepositEnabled(int $allianceId): bool
    {
        return $this->getDirectDepositTaxId($allianceId) > 0;
    }

    public function getGrowthCirclesTaxId(int $allianceId): int
    {
        return $this->configurationForAlliance($allianceId)['growth_circles_tax_id'];
    }

    public function getGrowthCirclesFallbackTaxId(int $allianceId): int
    {
        return $this->configurationForAlliance($allianceId)['growth_circles_fallback_tax_id'];
    }

    public function isGrowthCirclesEnabled(int $allianceId): bool
    {
        return $this->getGrowthCirclesTaxId($allianceId) > 0;
    }

    /**
     * @return array{
     *     direct_deposit_tax_id: int,
     *     direct_deposit_fallback_tax_id: int,
     *     growth_circles_tax_id: int,
     *     growth_circles_fallback_tax_id: int
     * }
     */
    private function configurationForAlliance(int $allianceId): array
    {
        if (isset($this->configurations[$allianceId])) {
            return $this->configurations[$allianceId];
        }

        $primaryAllianceId = $this->membershipService->getPrimaryAllianceId();

        if ($primaryAllianceId <= 0 || $allianceId === $primaryAllianceId) {
            return $this->configurations[$allianceId] = [
                'direct_deposit_tax_id' => $this->financePolicySettings->getDirectDepositId(),
                'direct_deposit_fallback_tax_id' => $this->financePolicySettings->getDirectDepositFallbackId(),
                'growth_circles_tax_id' => $this->financePolicySettings->getGrowthCirclesTaxId(),
                'growth_circles_fallback_tax_id' => $this->financePolicySettings->getGrowthCirclesFallbackTaxId(),
            ];
        }

        $offshore = Offshore::query()
            ->enabled()
            ->where('alliance_id', $allianceId)
            ->first([
                'direct_deposit_tax_id',
                'direct_deposit_fallback_tax_id',
                'growth_circles_tax_id',
                'growth_circles_fallback_tax_id',
            ]);

        return $this->configurations[$allianceId] = [
            'direct_deposit_tax_id' => (int) ($offshore?->direct_deposit_tax_id ?? 0),
            'direct_deposit_fallback_tax_id' => (int) ($offshore?->direct_deposit_fallback_tax_id ?? 0),
            'growth_circles_tax_id' => (int) ($offshore?->growth_circles_tax_id ?? 0),
            'growth_circles_fallback_tax_id' => (int) ($offshore?->growth_circles_fallback_tax_id ?? 0),
        ];
    }
}
