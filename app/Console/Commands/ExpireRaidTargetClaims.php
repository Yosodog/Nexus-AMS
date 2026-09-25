<?php

namespace App\Console\Commands;

use App\Services\Raids\RaidTargetClaimService;
use App\Services\RuntimeCapabilities;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('raids:expire-claims')]
#[Description('Expire raid target claims that passed their expiry time')]
final class ExpireRaidTargetClaims extends Command
{
    public function handle(RuntimeCapabilities $capabilities, RaidTargetClaimService $claims): int
    {
        if (! $capabilities->writesTenantPrivate()) {
            $this->components->info('Raid target claims are unavailable in this runtime.');

            return self::SUCCESS;
        }

        $this->components->info('Expired '.$claims->expireStale().' raid target claims.');

        return self::SUCCESS;
    }
}
