<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\WarDeclared;
use App\Services\RaidPredictionService;

final class CaptureRaidPredictionOnWarDeclared
{
    public function __construct(private readonly RaidPredictionService $predictions) {}

    public function handle(WarDeclared $event): void
    {
        $this->predictions->captureDeclaration($event);
    }
}
