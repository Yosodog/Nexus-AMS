<?php

namespace App\Notifications;

use App\Models\GrantApplications;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class GrantNotification extends Notification
{
    use Queueable;

    public int $nation_id;
    public string $status;
    public GrantApplications $application;

    /**
     * Create a new notification instance.
     */
    public function __construct(int $nation_id, GrantApplications $application, string $status)
    {
        $this->nation_id = $nation_id;
        $this->application = $application;
        $this->status = $status;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['pnw']; // Sends in-game messages via custom channel
    }

    /**
     * Format the in-game notification.
     */
    public function toPNW(object $notifiable): array
    {
        $grantName = $this->application->grant->name;

        if ($this->status === 'approved') {
            $subject = "Grant Approved!";
            $message = "Your application for the [b]{$grantName}[/b] has been approved! 🎉\n\n"
                . "The grant resources have been deposited into your selected account.\n\n"
                . "Please use these funds as intended and reach out to leadership if you have questions.";
        } else {
            $subject = "Grant Denied";
            $message = "Unfortunately, your application for the [b]{$grantName}[/b] has been denied. ❌\n\n"
                . "If you believe this was an error, please contact leadership for clarification.\n\n"
                . "You may apply again if you are still eligible.";
        }

        return [
            'nation_id' => $this->nation_id,
            'subject' => $subject,
            'message' => $message,
        ];
    }
}
