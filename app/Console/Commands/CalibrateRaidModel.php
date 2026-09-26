<?php

namespace App\Console\Commands;

use App\Services\Raids\RaidCalibrationService;
use App\Services\RuntimeCapabilities;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('raids:calibrate')]
#[Description('Calibrate raid stockpile intervals and bias from victory backtests')]
final class CalibrateRaidModel extends Command
{
    public function handle(RuntimeCapabilities $capabilities, RaidCalibrationService $calibration): int
    {
        if (! $capabilities->writesPublicWorld()) {
            $this->components->info('Raid calibration is unavailable in this runtime.');

            return self::SUCCESS;
        }

        $this->line((string) json_encode($calibration->calibrate(), JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }
}
