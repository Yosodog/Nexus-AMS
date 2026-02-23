<?php

namespace App\Notifications;

use App\Services\PWHelperService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class DepositCompletedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly int $nationId,
        private readonly ?string $accountName,
        private readonly array $resources,
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
        $accountLabel = $this->accountName ?? 'your account';

        $resourceLines = collect(PWHelperService::resources())
            ->filter(function (string $resource): bool {
                return (float) ($this->resources[$resource] ?? 0) > 0;
            })
            ->map(function (string $resource): string {
                $amount = (float) ($this->resources[$resource] ?? 0);
                $formattedAmount = number_format($amount, 2);
                $label = $resource === 'money' ? 'Money' : ucfirst($resource);

                return $resource === 'money'
                    ? "- {$label}: $".$formattedAmount
                    : "- {$label}: {$formattedAmount}";
            });

        $messageParts = [
            "Your deposit to [b]{$accountLabel}[/b] has been received and applied.",
        ];

        if ($resourceLines->isNotEmpty()) {
            $messageParts[] = "[b]Deposited resources:[/b]\n".$resourceLines->implode("\n");
        }

        return [
            'nation_id' => $this->nationId,
            'subject' => 'Deposit Confirmed',
            'message' => implode("\n\n", $messageParts),
        ];
    }
}
