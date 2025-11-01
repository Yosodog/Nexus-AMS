<?php

namespace App\Notifications;

use App\Models\Loan;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class LoanNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $nation_id;

    public string $status;

    public Loan $loan;

    public ?float $paymentAmount;

    /**
     * Create a new notification instance.
     */
    public function __construct(int $nation_id, Loan $loan, string $status, ?float $paymentAmount = null)
    {
        $this->nation_id = $nation_id;
        $this->loan = $loan;
        $this->status = $status;
        $this->paymentAmount = $paymentAmount;
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
            $subject = 'Loan Approved!';
            $message = 'Your loan request for [b]$'.number_format(
                $this->loan->amount,
                2
            )."[/b] has been approved! 🎉 \n\n"
                ."[b]Loan Details:[/b]\n"
                ."- Interest Rate: [b]{$this->loan->interest_rate}%[/b]\n"
                ."- Term: [b]{$this->loan->term_weeks} weeks[/b]\n"
                .'- Next due date: [b]'.($this->loan->next_due_date ? $this->loan->next_due_date->format(
                    'M d, Y'
                ) : 'N/A')."[/b]\n"
                ."- Funds have been deposited into your selected account.\n\n"
                .'Make sure to [b]repay your loan on time[/b] to avoid penalties.';
        } elseif ($this->status === 'denied') {
            $subject = 'Loan Denied';
            $message = 'Unfortunately, your loan request for [b]$'.number_format(
                $this->loan->amount,
                2
            )."[/b] has been denied. ❌\n\n"
                ."If you believe this was an error, please contact leadership for clarification.\n"
                .'You may apply again if eligible.';
        } elseif ($this->status === 'payment_success') {
            $subject = 'Loan Payment Successful!';
            $message = 'Your loan payment of [b]$'.number_format(
                $this->paymentAmount,
                2
            )."[/b] has been successfully processed. ✅\n\n"
                .'[b]Remaining Loan Balance:[/b] $'.number_format($this->loan->remaining_balance, 2)."\n"
                .'[b]Next Payment Due:[/b] '.($this->loan->next_due_date ? $this->loan->next_due_date->format(
                    'M d, Y'
                ) : 'N/A')."\n\n"
                .'Thank you for staying on top of your loan repayments!';
        } elseif ($this->status === 'paid') {
            $subject = 'Loan Fully Paid!';
            $message = 'Congratulations! 🎉 You have fully paid off your loan of [b]$'.number_format(
                $this->loan->amount,
                2
            )."[/b].\n\n"
                ."Your loan is now marked as [b]PAID[/b], and no further payments are required.\n\n"
                .'We appreciate your responsible financial management!';
        } elseif ($this->status === 'early_payment_applied') {
            $subject = 'Loan Payment Adjusted!';
            $message = "Your early loan payment has been applied to this week's scheduled payment! 🎉\n\n"
                ."Since you've already paid at least [b]$".number_format(
                    $this->loan->remaining_balance,
                    2
                ).'[/b] this week, '
                ."you owe nothing for this cycle.\n\n"
                ."Your next payment will be due on [b]{$this->loan->next_due_date->format('M d, Y')}[/b].";
        }

        return [
            'nation_id' => $this->nation_id,
            'subject' => $subject,
            'message' => $message,
        ];
    }
}
