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

    public function __construct(int $nation_id, WarAidRequest $request, string $status)
    {
        $this->nation_id = $nation_id;
        $this->request = $request;
        $this->status = $status;
    }

    /**
     * @return string[]
     */
    public function via(object $notifiable): array
    {
        return ['pnw'];
    }

    /**
     * @return array<int|string, mixed>
     */
    public function toPNW(object $notifiable): array
    {
        if ($this->status === 'approved') {
            $subject = 'War aid approved';
            $message = "Your war aid request has been approved.\n\n"
                ."Funds and resources have been deposited into your account.\n\n";
        } else {
            $subject = 'War aid denied';
            $message = "Your war aid request was denied.\n\n"
                ."Contact alliance leadership if you need the reason reviewed.\n\n";
        }

        return [
            'nation_id' => $this->nation_id,
            'subject' => $subject,
            'message' => $message,
        ];
    }
}
