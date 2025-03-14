<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class NationVerification extends Notification implements ShouldQueue
{
    use Queueable;

    public User $user;
    public string $verification_code;

    /**
     * Create a new notification instance.
     */
    public function __construct(User $user)
    {
        $this->user = $user;
        $this->verification_code = strtoupper(bin2hex(random_bytes(16)));

        // Save code to user model
        $user->update(['verification_code' => $this->verification_code]);
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['pnw'];
    }

    /**
     * @param object $notifiable
     *
     * @return array
     */
    public function toPNW(object $notifiable): array
    {
        return [
            'nation_id' => $this->user->nation_id,
            'subject' => "Verify Your Account",
            'message' => "Welcome to " . env(
                    "APP_NAME"
                ) . "! \n\nPlease verify your account by clicking the link below:\n\n" .
                route('verify.account', ['code' => $this->verification_code]) .
                "\n\nYour verification code: {$this->verification_code}"
        ];
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            //
        ];
    }
}
