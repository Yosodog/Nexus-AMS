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
     * @return array<string, mixed>
     */
    public function toPNW(object $notifiable): array
    {
        return [
            'nation_id' => $this->nation_id,
            'subject' => 'Deposit request created',
            'message' => "Deposit account: {$this->deposit->account->name}\n\nDeposit code: {$this->deposit->deposit_code}\n\nSend the money and resources you want to deposit to the in-game bank. Use the code above as the transaction note.\n\nThe app checks deposits every minute. Your balance updates after you receive a confirmation message. If you do not get one within an hour, contact us.\n\nThis code expires in one hour. Deposits sent after it expires will not be counted.",
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
