<?php

namespace App\Notifications;

use App\Models\GrantApplication;
use App\Notifications\Concerns\SendsPrivateDiscordNotification;
use App\Support\PWBBCode;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class GrantNotification extends Notification
{
    use Queueable;
    use SendsPrivateDiscordNotification;

    public int $nation_id;

    public string $status;

    public GrantApplication $application;

    /**
     * Create a new notification instance.
     */
    public function __construct(int $nation_id, GrantApplication $application, string $status)
    {
        $this->nation_id = $nation_id;
        $this->application = $application;
        $this->status = $status;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return $this->pnwAndPrivateDiscordChannels($notifiable, 'grants');
    }

    public function toDiscordBot(object $notifiable): array
    {
        $summary = ['status' => $this->status];

        if ($this->status === 'denied') {
            $summary['reason'] = $this->application->decision_reason_code?->label() ?? 'Not recorded';
            $summary['explanation'] = $this->application->memberDecisionExplanation() ?? 'Not recorded';
        }

        return $this->privateDiscordMessage(
            $notifiable,
            'grant_application_'.$this->status,
            [
                'type' => 'grant_application',
                'id' => $this->application->id,
                'label' => $this->application->program_name_snapshot ?? 'Grant request',
            ],
            route('grants.history', absolute: false),
            $summary,
        );
    }

    /**
     * Format the in-game notification.
     */
    public function toPNW(object $notifiable): array
    {
        $grantName = PWBBCode::escapeText($this->application->program_name_snapshot ?? 'the requested grant');

        if ($this->status === 'approved') {
            $subject = 'Grant approved';
            $message = "Your application for [b]{$grantName}[/b] has been approved.\n\n"
                .'The grant resources have been deposited into your selected account.';
        } else {
            $reason = PWBBCode::escapeText($this->application->decision_reason_code?->label() ?? 'Not recorded');
            $explanation = PWBBCode::escapeText($this->application->memberDecisionExplanation() ?? 'No member-visible explanation was recorded.');
            $subject = 'Grant denied';
            $message = "Your application for [b]{$grantName}[/b] was denied.\n\n"
                ."Reason: [b]{$reason}[/b]\n{$explanation}\n\n"
                .'You may apply again if you are still eligible.';
        }

        return [
            'nation_id' => $this->nation_id,
            'subject' => $subject,
            'message' => $message,
        ];
    }
}
