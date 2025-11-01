<?php

namespace App\Notifications;

use App\Models\Transaction;
use App\Services\PWHelperService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class WithdrawalDeniedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $nationId;

    public Transaction $transaction;

    public ?string $accountName;

    public function __construct(int $nationId, Transaction $transaction, ?string $accountName = null)
    {
        $this->nationId = $nationId;
        $this->transaction = $transaction;
        $this->accountName = $accountName;
    }

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
        $submittedAt = $this->transaction->created_at
            ? $this->transaction->created_at->timezone('UTC')->format('M d, Y H:i').' UTC'
            : null;

        $resourceLines = collect(PWHelperService::resources())
            ->filter(function (string $resource) {
                return (float) $this->transaction->{$resource} > 0;
            })
            ->map(function (string $resource) {
                $amount = (float) $this->transaction->{$resource};
                $formattedAmount = number_format($amount, 2);
                $label = $resource === 'money' ? 'Money' : ucfirst($resource);

                return $resource === 'money'
                    ? "- {$label}: $".$formattedAmount
                    : "- {$label}: {$formattedAmount}";
            });

        $messageParts = [
            "Your withdrawal request from [b]{$accountLabel}[/b] has been denied. ❌",
        ];

        if ($submittedAt) {
            $messageParts[] = "[b]Submitted:[/b] {$submittedAt}";
        }

        if ($resourceLines->isNotEmpty()) {
            $messageParts[] = "[b]Requested resources:[/b]\n".$resourceLines->implode("\n");
        }

        if (! empty($this->transaction->denial_reason)) {
            $messageParts[] = "[b]Reason provided:[/b]\n".$this->transaction->denial_reason;
        }

        $messageParts[] = 'The funds have been returned to the source account. Please reach out to leadership if you have questions.';

        return [
            'nation_id' => $this->nationId,
            'subject' => 'Withdrawal Denied',
            'message' => implode("\n\n", $messageParts),
        ];
    }
}
