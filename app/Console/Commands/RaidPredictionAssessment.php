<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\RaidAssessmentService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('raid:prediction-assessment {--days=30 : Assessment window in days} {--nation= : Limit to an attacker nation ID}')]
#[Description('Report rolling raid prediction accuracy')]
final class RaidPredictionAssessment extends Command
{
    public function handle(RaidAssessmentService $assessment): int
    {
        $days = $this->option('days');
        if (! is_numeric($days) || (int) $days < 1 || (int) $days > 3650) {
            $this->components->error('The --days option must be between 1 and 3650.');

            return self::INVALID;
        }

        $nation = $this->option('nation');
        if ($nation !== null && (! is_numeric($nation) || (int) $nation < 1)) {
            $this->components->error('The --nation option must be a positive integer.');

            return self::INVALID;
        }

        $to = CarbonImmutable::now();
        $report = $assessment->assess(
            $to->subDays((int) $days),
            $to,
            $nation === null ? null : (int) $nation,
        );

        $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
