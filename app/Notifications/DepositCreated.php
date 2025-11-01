<?php

namespace App\Notifications;

use App\Models\DepositRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class DepositCreated extends Notification implements ShouldQueue
{
    use Queueable;

    public int $nation_id;

    public DepositRequest $deposit;

    /**
     * Create a new notification instance.
     */
    public function __construct(int $nation_id, DepositRequest $deposit)
    {
        $this->nation_id = $nation_id;
        $this->deposit = $deposit;
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
     * @return array
     */
    public function toPNW(object $notifiable)
    {
        return [
            'nation_id' => $this->nation_id,
            'subject' => 'Deposit Request Created',
            'message' => "A deposit request for the account named: {$this->deposit->account->name} has been created.\n\nYour code is: {$this->deposit->deposit_code}\n\nPlease send whatever money and resources you want to deposit into your account into the in-game bank using the code above as the transaction note.\n\nPlease note that the system checks for deposits every minute, which means that your deposit will not show up in your account until you receive a confirmation message. If you do not get a message within one hour, please contact us.\n\nAdditionally, your deposit code will expire in one hour. If you do not use this code within one hour, your code will be invalid and your deposit not counted.",
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
