<?php

namespace App\Notifications;

use App\Models\WarAidRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class WarAidNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $nation_id;
    public string $status;
    public WarAidRequest $request;

    /**
     * @param int $nation_id
     * @param WarAidRequest $request
     * @param string $status
     */
    public function __construct(int $nation_id, WarAidRequest $request, string $status)
    {
        $this->nation_id = $nation_id;
        $this->request = $request;
        $this->status = $status;
    }

    /**
     * @param object $notifiable
     * @return string[]
     */
    public function via(object $notifiable): array
    {
        return ['pnw'];
    }

    /**
     * @param object $notifiable
     * @return array
     */
    public function toPNW(object $notifiable): array
    {
        if ($this->status === 'approved') {
            $subject = "War Aid Approved!";
            $message = "Your war aid request has been approved! 🎖️\n\n"
                . "Funds and resources have been deposited into your account.\n\n";
        } else {
            $subject = "War Aid Denied";
            $message = "Unfortunately, your war aid request has been denied. ❌\n\n"
                . "If you need clarification, please reach out to alliance leadership.\n\n";
        }

        return [
            'nation_id' => $this->nation_id,
            'subject' => $subject,
            'message' => $message,
        ];
    }
}