<?php

namespace App\Notifications;

use App\Models\RebuildingRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class RebuildingNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly int $nationId,
        private readonly RebuildingRequest $request,
        private readonly string $status,
    ) {}

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
     * @return array<int|string, mixed>
     */
    public function toPNW(object $notifiable): array
    {
        if ($this->status === 'approved') {
            $subject = 'Rebuilding approved';
            $amount = (int) round($this->request->approved_amount ?? $this->request->estimated_amount ?? 0);
            $message = "Your rebuilding request has been approved.\n\n"
                .'Approved amount: $'.number_format($amount)."\n"
                ."Account: {$this->request->account?->name}\n\n"
                .'Funds have been deposited in your selected account.';

            return [
                'nation_id' => $this->nationId,
                'subject' => $subject,
                'message' => $message,
            ];
        }

        $subject = 'Rebuilding denied';
        $message = "Your rebuilding request has been denied.\n\n"
            .'Contact alliance leadership if you need the reason reviewed. You may reapply if eligible.';

        return [
            'nation_id' => $this->nationId,
            'subject' => $subject,
            'message' => $message,
        ];
    }
}
