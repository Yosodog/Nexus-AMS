<?php

namespace App\Enums;

enum LoanStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Denied = 'denied';
    case Paid = 'paid';
    case Missed = 'missed';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** @return list<string> */
    public static function activeValues(): array
    {
        return [self::Approved->value, self::Missed->value];
    }

    /**
     * Return canonical and retained legacy values for attention-only queries.
     *
     * @return list<string>
     */
    public static function attentionValues(): array
    {
        return [self::Missed->value, 'past_due'];
    }

    public static function fromStoredValue(string $value): self|string
    {
        return self::tryFrom($value) ?? $value;
    }

    public static function scalar(self|string $status): string
    {
        return $status instanceof self ? $status->value : $status;
    }

    /**
     * @return array{label: string, intent: string, icon: string, explanation: string}
     */
    public static function presentationFor(self|string $status): array
    {
        $knownStatus = $status instanceof self ? $status : self::tryFrom($status);

        return $knownStatus?->presentation() ?? [
            'label' => 'Unknown',
            'intent' => 'neutral',
            'icon' => 'minus-circle',
            'explanation' => 'The loan status is unavailable. Contact staff if this looks wrong.',
        ];
    }

    public function isPending(): bool
    {
        return $this === self::Pending;
    }

    public function isActive(): bool
    {
        return in_array($this, [self::Approved, self::Missed], true);
    }

    public function isRepayable(): bool
    {
        return $this->canTransitionTo(self::Paid);
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Denied, self::Paid], true);
    }

    public function canTransitionTo(self $nextStatus): bool
    {
        return match ($this) {
            self::Pending => in_array($nextStatus, [self::Approved, self::Denied], true),
            self::Approved => in_array($nextStatus, [self::Missed, self::Paid], true),
            self::Missed => in_array($nextStatus, [self::Approved, self::Paid], true),
            self::Denied, self::Paid => false,
        };
    }

    /**
     * @return array{label: string, intent: string, icon: string, explanation: string}
     */
    public function presentation(): array
    {
        return match ($this) {
            self::Pending => [
                'label' => 'Pending',
                'intent' => 'pending',
                'icon' => 'clock',
                'explanation' => 'Awaiting staff review.',
            ],
            self::Approved => [
                'label' => 'Approved',
                'intent' => 'active',
                'icon' => 'bolt',
                'explanation' => 'Repayment is in progress.',
            ],
            self::Denied => [
                'label' => 'Denied',
                'intent' => 'failure',
                'icon' => 'x-circle',
                'explanation' => 'The loan request was not approved.',
            ],
            self::Paid => [
                'label' => 'Paid',
                'intent' => 'success',
                'icon' => 'check-circle',
                'explanation' => 'The loan has been repaid.',
            ],
            self::Missed => [
                'label' => 'Missed',
                'intent' => 'warning',
                'icon' => 'exclamation-triangle',
                'explanation' => 'A scheduled payment is past due.',
            ],
        };
    }
}
