<?php

namespace App\Notifications;

use App\Models\CityGrantRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class CityGrantNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $nation_id;

    public string $status;

    public CityGrantRequest $request;

    /**
     * Create a new notification instance.
     */
    public function __construct(int $nation_id, CityGrantRequest $request, string $status)
    {
        $this->nation_id = $nation_id;
        $this->request = $request;
        $this->status = $status;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['pnw']; // Send to Politics & War in-game messages
    }

    /**
     * Format the in-game notification.
     */
    public function toPNW(object $notifiable)
    {
        if ($this->status === 'approved') {
            $subject = 'City Grant Approved!';
            $message = "Your city grant request for City #{$this->request->city_number} has been approved! 🎉 \n\n"
                ."Funds have been deposited into your selected account.\n\n"
                .'Please withdraw these funds as soon as possible and purchase your city.';
        } else {
            $subject = 'City Grant Denied';
            $message = "Unfortunately, your city grant request for City #{$this->request->city_number} has been denied. ❌\n\n"
                ."If you believe this was an error, please contact leadership for clarification.\n\n"
                .'You may apply again if eligible.';
        }

        return [
            'nation_id' => $this->nation_id,
            'subject' => $subject,
            'message' => $message,
        ];
    }
}
