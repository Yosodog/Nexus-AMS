<?php

namespace App\Services\Inactivity\Actions;

use App\Models\InactivityEvent;
use App\Models\Nation;
use App\Services\Inactivity\InactivityActionContext;
use App\Services\Inactivity\InactivityActionHandler;
use App\Services\Inactivity\InactivityActionResult;
use App\Services\Inactivity\InactivityMessageBuilder;
use App\Services\PWMessageService;

class SendInGameMessageAction implements InactivityActionHandler
{
    public function __construct(
        private readonly PWMessageService $messageService,
        private readonly InactivityMessageBuilder $messageBuilder
    ) {}

    public function handle(Nation $nation, InactivityEvent $event, InactivityActionContext $context): InactivityActionResult
    {
        $subject = $this->messageBuilder->buildSubject();

        if ($context->autoEnrolledDirectDeposit && $context->accountsUrl) {
            $message = $this->messageBuilder->buildAutoEnrollMessage(
                $nation->leader_name,
                $context->lastActiveAt,
                $context->thresholdHours,
                $context->accountsUrl
            );
        } else {
            $message = $this->messageBuilder->buildInactiveNoticeMessage(
                $nation->leader_name,
                $context->lastActiveAt,
                $context->thresholdHours
            );
        }

        $sent = $this->messageService->sendMessage($nation->id, $subject, $message);

        return new InactivityActionResult(notificationSent: $sent);
    }
}
