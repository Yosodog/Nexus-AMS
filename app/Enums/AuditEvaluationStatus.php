<?php

namespace App\Enums;

enum AuditEvaluationStatus: string
{
    case NeverRun = 'never_run';
    case Pending = 'pending';
    case Success = 'success';
    case Warning = 'warning';
    case Failed = 'failed';
    case MigrationFailed = 'migration_failed';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function label(): string
    {
        return match ($this) {
            self::NeverRun => 'Never run',
            self::Pending => 'Pending evaluation',
            self::Success => 'Healthy',
            self::Warning => 'Completed with warnings',
            self::Failed => 'Evaluation failed',
            self::MigrationFailed => 'Needs rebuild',
        };
    }
}
