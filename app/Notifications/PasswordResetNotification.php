<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

class PasswordResetNotification extends Notification
{
    protected string $token;

    public function __construct(string $token)
    {
        $this->token = $token;
    }

    /**
     * @return array<int|string, mixed>
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
        $resetUrl = route('password.reset', [
            'token' => $this->token,
            'email' => $notifiable->email,
        ], true);

        $subject = 'Reset your password';

        $message = "[b]Password reset requested[/b]\n\n"
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
