<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class AuditViolationSummaryNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<int, string>  $lines
     */
    public function __construct(
        public int $nationId,
        public array $lines,
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
        $header = 'Your nation currently has the following audit findings:';

        $bodyLines = collect($this->lines)
            ->filter()
            ->map(fn (string $line) => "- {$line}")
            ->implode("\n");

        $footer = 'Please address these issues to clear the alerts. If you believe any are inaccurate, contact leadership.';

        return [
            'nation_id' => $this->nationId,
            'subject' => 'Nation Audit: Violations Summary',
            'message' => "{$header}\n\n{$bodyLines}\n\n{$footer}",
        ];
    }
}
