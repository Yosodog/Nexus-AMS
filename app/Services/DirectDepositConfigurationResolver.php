<?php

namespace App\Services;

use App\DataTransferObjects\DirectDepositConfiguration;
use App\Models\Nation;
use App\Models\Offshore;

class DirectDepositConfigurationResolver
{
    public function forNation(Nation $nation, bool $lockForUpdate = false): DirectDepositConfiguration
    {
        return $this->forAlliance((int) $nation->alliance_id, $lockForUpdate);
    }

    public function forAlliance(int $allianceId, bool $lockForUpdate = false): DirectDepositConfiguration
    {
        $primaryAllianceId = (int) config('services.pw.alliance_id', 0);

        if ($allianceId > 0 && $allianceId === $primaryAllianceId) {
            $taxId = SettingService::getDirectDepositId();
            $fallbackTaxId = SettingService::getDirectDepositFallbackId();
            $hasCredentials = filled(config('services.pw.api_key'))
                && filled(config('services.pw.mutation_key'));

            return new DirectDepositConfiguration(
                allianceId: $allianceId,
                enabled: $taxId > 0,
                taxId: $taxId > 0 ? $taxId : null,
                fallbackTaxId: $fallbackTaxId > 0 ? $fallbackTaxId : null,
                unavailableReason: $hasCredentials
                    ? $this->configurationReason($taxId, $fallbackTaxId)
                    : 'Direct Deposit mutation credentials are not configured.',
            );
        }

        $query = Offshore::query()
            ->where('alliance_id', $allianceId)
            ->orderByDesc('enabled')
            ->orderBy('priority');

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        $offshore = $query->first();

        if (! $offshore) {
            return new DirectDepositConfiguration(
                allianceId: $allianceId,
                enabled: false,
                taxId: null,
                fallbackTaxId: null,
                unavailableReason: 'Your nation is not in an alliance configured for Direct Deposit.',
            );
        }

        $reason = null;

        if (! $offshore->enabled) {
            $reason = 'Your offshore is currently disabled.';
        } elseif (! $offshore->direct_deposit_enabled) {
            $reason = 'Direct Deposit is not enabled for your offshore.';
        } elseif (! $offshore->api_key_decrypted || ! $offshore->mutation_key_decrypted) {
            $reason = 'Direct Deposit credentials are not configured for your offshore.';
        } else {
            $reason = $this->configurationReason(
                (int) $offshore->direct_deposit_tax_id,
                (int) $offshore->direct_deposit_fallback_tax_id,
            );
        }

        return new DirectDepositConfiguration(
            allianceId: $allianceId,
            enabled: $offshore->enabled && $offshore->direct_deposit_enabled,
            taxId: $offshore->direct_deposit_tax_id,
            fallbackTaxId: $offshore->direct_deposit_fallback_tax_id,
            offshoreId: $offshore->id,
            unavailableReason: $reason,
        );
    }

    private function configurationReason(int $taxId, int $fallbackTaxId): ?string
    {
        if ($taxId <= 0 || $fallbackTaxId <= 0) {
            return 'Direct Deposit tax IDs are incomplete.';
        }

        if ($taxId === $fallbackTaxId) {
            return 'The Direct Deposit and fallback tax IDs must be different.';
        }

        return null;
    }
}
