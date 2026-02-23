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
                .'- Scheduled weekly payment: [b]$'.number_format((float) $this->loan->scheduled_weekly_payment, 2)."[/b]\n"
                ."- Funds have been deposited into your selected account.\n\n"
                .'Your payment amount follows weekly amortization (interest first, then principal).';
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
                    $this->paymentAmount ?? 0,
                    2
                ).'[/b] toward this week, '
                ."you owe nothing for this cycle.\n\n"
                ."Your next payment will be due on [b]{$this->loan->next_due_date->format('M d, Y')}[/b].";
        } elseif ($this->status === 'missed_payment') {
            $subject = 'Loan Payment Missed';
            $message = 'A scheduled loan payment was missed, and the amount due has rolled forward.'."\n\n"
                .'[b]Current Amount Due:[/b] $'.number_format((float) ($this->paymentAmount ?? 0), 2)."\n"
                .'[b]Past Due Amount:[/b] $'.number_format((float) $this->loan->past_due_amount, 2)."\n"
                .'[b]Accrued Interest Due:[/b] $'.number_format((float) $this->loan->accrued_interest_due, 2)."\n\n"
                .'Make a payment when possible to reduce accrued interest and principal.';
        } else {
            $subject = 'Loan Update';
            $message = 'Your loan has been updated.';
        }

        return [
            'nation_id' => $this->nation_id,
            'subject' => $subject,
            'message' => $message,
        ];
    }
}
