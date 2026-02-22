<?php

namespace App\Notifications;

use App\Models\Transaction;
use App\Services\PWHelperService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class WithdrawalSentNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly int $nationId,
        private readonly Transaction $transaction,
        private readonly ?string $accountName = null,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['pnw'];
    }

    /**
     * @return array<string, string>
     */
    public function toPNW(object $notifiable): array
    {
        $accountLabel = $this->accountName ?? 'your alliance bank account';
        $sentAt = $this->transaction->sent_at
            ? $this->transaction->sent_at->timezone('UTC')->format('M d, Y H:i').' UTC'
            : null;

        $resourceLines = collect(PWHelperService::resources())
            ->filter(function (string $resource): bool {
                return (float) $this->transaction->{$resource} > 0;
            })
            ->map(function (string $resource): string {
                $amount = (float) $this->transaction->{$resource};
                $formattedAmount = number_format($amount, 2);
                $label = $resource === 'money' ? 'Money' : ucfirst($resource);

                return $resource === 'money'
                    ? "- {$label}: $".$formattedAmount
                    : "- {$label}: {$formattedAmount}";
            });

        $messageParts = [
            "Your withdrawal from [b]{$accountLabel}[/b] was sent successfully. ✅",
        ];

        if ($this->transaction->approved_at) {
            $messageParts[] = 'Approval: approved by an admin.';
        } else {
            $messageParts[] = 'Approval: auto-approved by system limits.';
        }

        if ($sentAt) {
            $messageParts[] = "[b]Sent:[/b] {$sentAt}";
        }

        $messageParts[] = '[b]Transaction ID:[/b] #'.$this->transaction->id;

        if (! empty($this->transaction->note)) {
            $messageParts[] = '[b]Note:[/b] '.$this->transaction->note;
        }

        if ($resourceLines->isNotEmpty()) {
            $messageParts[] = "[b]Resources sent:[/b]\n".$resourceLines->implode("\n");
        }

        return [
            'nation_id' => $this->nationId,
            'subject' => 'Withdrawal Sent',
            'message' => implode("\n\n", $messageParts),
        ];
    }
}
