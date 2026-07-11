<?php

namespace App\Notifications;

use App\Models\Loan;
use App\Notifications\Concerns\SendsPrivateDiscordNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class LoanNotification extends Notification implements ShouldQueue
{
    use Queueable;
    use SendsPrivateDiscordNotification;

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
        return $this->pnwAndPrivateDiscordChannels($notifiable, 'loans');
    }

    public function toDiscordBot(object $notifiable): array
    {
        return $this->privateDiscordMessage(
            $notifiable,
            'loan_'.$this->status,
            ['type' => 'loan', 'id' => $this->loan->id],
            '/loans',
            ['status' => $this->status],
        );
    }

    /**
     * Format the in-game notification.
     */
    public function toPNW(object $notifiable)
    {
        if ($this->status === 'approved') {
            $subject = 'Loan approved';
            $message = 'Your loan request for [b]$'.number_format(
                $this->loan->amount,
                2
            )."[/b] has been approved.\n\n"
                ."[b]Loan details:[/b]\n"
                ."- Interest rate: [b]{$this->loan->interest_rate}%[/b]\n"
                ."- Term: [b]{$this->loan->term_weeks} weeks[/b]\n"
                .'- Next due date: [b]'.($this->loan->next_due_date ? $this->loan->next_due_date->format(
                    'M d, Y'
                ) : 'N/A')."[/b]\n"
                .'- Scheduled weekly payment: [b]$'.number_format((float) $this->loan->scheduled_weekly_payment, 2)."[/b]\n"
                ."- Funds have been deposited into your selected account.\n\n"
                .'Your payment amount follows weekly amortization (interest first, then principal).';
        } elseif ($this->status === 'denied') {
            $subject = 'Loan denied';
            $message = 'Your loan request for [b]$'.number_format(
                $this->loan->amount,
                2
            )."[/b] was denied.\n\n"
                ."Contact leadership if you need the reason reviewed.\n"
                .'You may apply again if eligible.';
        } elseif ($this->status === 'payment_success') {
            $subject = 'Loan payment received';
            $message = 'Your loan payment of [b]$'.number_format(
                $this->paymentAmount,
                2
            )."[/b] has been processed.\n\n"
                .'[b]Remaining loan balance:[/b] $'.number_format($this->loan->remaining_balance, 2)."\n"
                .'[b]Next payment due:[/b] '.($this->loan->next_due_date ? $this->loan->next_due_date->format(
                    'M d, Y'
                ) : 'N/A')."\n\n"
                .'Thanks for keeping your loan current.';
        } elseif ($this->status === 'paid') {
            $subject = 'Loan paid off';
            $message = 'Your loan of [b]$'.number_format(
                $this->loan->amount,
                2
            )."[/b].\n\n"
                .'It is now marked as [b]PAID[/b], and no further payments are required.';
        } elseif ($this->status === 'early_payment_applied') {
            $subject = 'Loan payment adjusted';
            $message = "Your early loan payment has been applied to this week's scheduled payment.\n\n"
                ."Since you've already paid at least [b]$".number_format(
                    $this->paymentAmount ?? 0,
                    2
                ).'[/b] toward this week, '
                ."you owe nothing for this cycle.\n\n"
                ."Your next payment will be due on [b]{$this->loan->next_due_date->format('M d, Y')}[/b].";
        } elseif ($this->status === 'missed_payment') {
            $subject = 'Loan payment missed';
            $message = 'A scheduled loan payment was missed, and the amount due has rolled forward.'."\n\n"
                .'[b]Current amount due:[/b] $'.number_format((float) ($this->paymentAmount ?? 0), 2)."\n"
                .'[b]Past due amount:[/b] $'.number_format((float) $this->loan->past_due_amount, 2)."\n"
                .'[b]Accrued interest due:[/b] $'.number_format((float) $this->loan->accrued_interest_due, 2)."\n\n"
                .'Make a payment when possible to reduce accrued interest and principal.';
        } else {
            $subject = 'Loan update';
            $message = 'Your loan has been updated.';
        }

        return [
            'nation_id' => $this->nation_id,
            'subject' => $subject,
            'message' => $message,
        ];
    }
}
