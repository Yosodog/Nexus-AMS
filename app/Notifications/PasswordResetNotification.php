<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class PasswordResetNotification extends Notification implements ShouldQueue
{
    use Queueable;

    protected string $token;

    public function __construct(string $token)
    {
        $this->token = $token;
    }

    public function via(object $notifiable): array
    {
        return ['pnw'];
    }

    public function toPNW(object $notifiable): array
    {
        $resetUrl = route('password.reset', [
            'token' => $this->token,
            'email' => $notifiable->email,
        ], true);

        $subject = 'Reset Your Password';

        $message = "[b]Password Reset Requested[/b]\n\n"
            ."We received a request to reset the password for your account.\n\n"
            ."Use the link below to choose a new password:\n"
            ."{$resetUrl}\n\n"
            .'This link expires in 60 minutes. If you did not request this reset, you can ignore this message.';

        return [
            'nation_id' => $notifiable->nation_id,
            'subject' => $subject,
            'message' => $message,
        ];
    }
}
