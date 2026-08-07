<?php

namespace App\Domain\Milcom\Enums;

enum OperationStatus: string
{
    case Draft = 'draft';
    case Generating = 'generating';
    case Review = 'review';
    case Dispatching = 'dispatching';
    case Active = 'active';
    case Completed = 'completed';
    case Archived = 'archived';
    case Failed = 'failed';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Archived], true);
    }

    /**
     * @return array{label: string, intent: string, icon: string, explanation: string}
     */
    public function presentation(): array
    {
        return match ($this) {
            self::Draft => [
                'label' => 'Draft',
                'intent' => 'neutral',
                'icon' => 'pencil-square',
                'explanation' => 'Setup is still in progress.',
            ],
            self::Generating => [
                'label' => 'Building teams',
                'intent' => 'pending',
                'icon' => 'arrow-path',
                'explanation' => 'Milcom is building recommendations.',
            ],
            self::Review => [
                'label' => 'Ready for review',
                'intent' => 'pending',
                'icon' => 'eye',
                'explanation' => 'Recommendations are ready for staff review.',
            ],
            self::Dispatching => [
                'label' => 'Creating Discord rooms',
                'intent' => 'active',
                'icon' => 'paper-airplane',
                'explanation' => 'Milcom is creating the operation rooms.',
            ],
            self::Active => [
                'label' => 'Active',
                'intent' => 'active',
                'icon' => 'bolt',
                'explanation' => 'Assignments are live.',
            ],
            self::Completed => [
                'label' => 'Completed',
                'intent' => 'success',
                'icon' => 'check-circle',
                'explanation' => 'The operation has finished.',
            ],
            self::Archived => [
                'label' => 'Archived',
                'intent' => 'neutral',
                'icon' => 'archive-box',
                'explanation' => 'Retained for reference.',
            ],
            self::Failed => [
                'label' => 'Failed',
                'intent' => 'failure',
                'icon' => 'x-circle',
                'explanation' => 'The operation could not be completed.',
            ],
        };
    }
}
