<?php

namespace App\Broadcasting;

use App\Services\PWMessageService;
use Illuminate\Notifications\Notification;

class PWMessageChannel
{
    protected PWMessageService $messageService;

    public function __construct(PWMessageService $messageService)
    {
        $this->messageService = $messageService;
    }

    /**
     * @param object $notifiable
     * @param Notification $notification
     *
     * @return void
     */
    public function send(object $notifiable, Notification $notification): void
    {
        if (!method_exists($notification, 'toPNW')) {
            return;
        }


        $data = $notification->toPNW($notifiable);

        if ($data) {
            $this->messageService->sendMessage($data['nation_id'], $data['subject'], $data['message']);
        }
    }
}
