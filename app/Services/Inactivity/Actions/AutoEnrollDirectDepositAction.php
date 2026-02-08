<?php

namespace App\Services\Inactivity\Actions;

use App\Models\DirectDepositEnrollment;
use App\Models\InactivityEvent;
use App\Models\Nation;
use App\Services\DirectDepositService;
use App\Services\Inactivity\InactivityActionContext;
use App\Services\Inactivity\InactivityActionHandler;
use App\Services\Inactivity\InactivityActionResult;
use App\Services\SettingService;

class AutoEnrollDirectDepositAction implements InactivityActionHandler
{
    public function __construct(private readonly DirectDepositService $directDepositService) {}

    public function handle(Nation $nation, InactivityEvent $event, InactivityActionContext $context): InactivityActionResult
    {
        if (! SettingService::isDirectDepositEnabled()) {
            return new InactivityActionResult;
        }

        if ($context->wasDirectDepositEnrolled) {
            return new InactivityActionResult;
        }

        if ($event->dd_opted_out_at) {
            return new InactivityActionResult;
        }

        if (DirectDepositEnrollment::where('nation_id', $nation->id)->exists()) {
            return new InactivityActionResult;
        }

        $account = $this->directDepositService->getDepositAccount($nation);
        $this->directDepositService->enroll($nation, $account);

        $event->forceFill(['dd_autoenrolled_at' => $context->now])->save();
        $context->autoEnrolledDirectDeposit = true;

        return new InactivityActionResult;
    }
}
