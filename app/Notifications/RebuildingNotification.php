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

    public function toPNW(object $notifiable): array
    {
        if ($this->status === 'approved') {
            $subject = 'Rebuilding Approved';
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

        $subject = 'Rebuilding Denied';
        $message = "Your rebuilding request has been denied.\n\n"
            .'If you need clarification, contact alliance leadership. You may reapply if eligible.';

        return [
            'nation_id' => $this->nationId,
            'subject' => $subject,
            'message' => $message,
        ];
    }
}
