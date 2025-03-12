<?php

namespace App\Notifications;

use App\Models\Loans;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class LoanNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $nation_id;
    public string $status;
    public Loans $loan;

    /**
     * Create a new notification instance.
     */
    public function __construct(int $nation_id, Loans $loan, string $status)
    {
        $this->nation_id = $nation_id;
        $this->loan = $loan;
        $this->status = $status;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['pnw']; // Sends notification to Politics & War in-game messages
    }

    /**
     * Format the in-game notification.
     */
    public function toPNW(object $notifiable)
    {
        if ($this->status === 'approved') {
            $subject = "Loan Approved!";
            $message = "Your loan request for [b]\${$this->loan->amount}[/b] has been approved! 🎉 \n\n"
                . "[b]Loan Details:[/b]\n"
                . "- Interest Rate: [b]{$this->loan->interest_rate}%[/b]\n"
                . "- Term: [b]{$this->loan->term_weeks} weeks[/b]\n"
                . "- Funds have been deposited into your selected account.\n\n"
                . "Make sure to [b]repay your loan on time[/b] to avoid penalties.";
        } else {
            $subject = "Loan Denied";
            $message = "Unfortunately, your loan request for [b]\${$this->loan->amount}[/b] has been denied. ❌\n\n"
                . "If you believe this was an error, please contact leadership for clarification.\n"
                . "You may apply again if eligible.";
        }

        return [
            'nation_id' => $this->nation_id,
            'subject' => $subject,
            'message' => $message
        ];
    }
}