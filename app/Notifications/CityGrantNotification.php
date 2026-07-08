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
            $subject = 'City grant approved';
            $message = "Your city grant request for City #{$this->request->city_number} has been approved.\n\n"
                .'Funds have been deposited into your selected account. Withdraw them when you are ready to buy the city.';
        } else {
            $subject = 'City grant denied';
            $message = "Your city grant request for City #{$this->request->city_number} was denied.\n\n"
                ."Contact leadership if you need the reason reviewed.\n\n"
                .'You may apply again if eligible.';
        }

        return [
            'nation_id' => $this->nation_id,
            'subject' => $subject,
            'message' => $message,
        ];
    }
}
