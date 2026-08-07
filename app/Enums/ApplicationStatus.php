<?php

namespace App\Enums;

enum ApplicationStatus: string
{
    case Pending = 'PENDING';
    case Approved = 'APPROVED';
    case Denied = 'DENIED';
    case Cancelled = 'CANCELLED';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
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
                'intent' => 'success',
                'icon' => 'check-circle',
                'explanation' => 'Approved for onboarding.',
            ],
            self::Denied => [
                'label' => 'Denied',
                'intent' => 'failure',
                'icon' => 'x-circle',
                'explanation' => 'The application was not approved.',
            ],
            self::Cancelled => [
                'label' => 'Cancelled',
                'intent' => 'neutral',
                'icon' => 'minus-circle',
                'explanation' => 'Closed without a decision.',
            ],
        };
    }
}
