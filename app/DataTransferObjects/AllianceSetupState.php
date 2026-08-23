<?php

namespace App\DataTransferObjects;

use App\Enums\AllianceSetupStatus;
use App\Enums\AllianceSetupStep;
use JsonException;
use ValueError;

final readonly class AllianceSetupState
{
    public const VERSION = 1;

    public function __construct(
        public int $version,
        public AllianceSetupStatus $status,
        public AllianceSetupStep $currentStep,
        public ?string $introAcknowledgedAt = null,
        public ?int $introAcknowledgedBy = null,
        public ?string $startedAt = null,
        public ?int $startedBy = null,
        public ?string $completedAt = null,
        public ?int $completedBy = null,
        public bool $stored = true,
        public bool $legacy = false,
        public bool $corrupt = false,
    ) {}

    public static function fresh(): self
    {
        return new self(self::VERSION, AllianceSetupStatus::NotStarted, AllianceSetupStep::Platform);
    }

    public static function legacyCompleted(): self
    {
        return new self(self::VERSION, AllianceSetupStatus::Completed, AllianceSetupStep::Review, stored: false, legacy: true);
    }

    public static function corruptState(): self
    {
        return new self(self::VERSION, AllianceSetupStatus::NotStarted, AllianceSetupStep::Platform, corrupt: true);
    }

    public static function fromJson(string $json): self
    {
        try {
            $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return self::corruptState();
        }

        return is_array($data) ? self::fromArray($data) : self::corruptState();
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        try {
            if (($data['version'] ?? null) !== self::VERSION) {
                return self::corruptState();
            }

            return new self(
                self::VERSION,
                AllianceSetupStatus::from((string) ($data['status'] ?? '')),
                AllianceSetupStep::from((string) ($data['current_step'] ?? '')),
                self::nullableString($data['intro_acknowledged_at'] ?? null),
                self::nullableInt($data['intro_acknowledged_by'] ?? null),
                self::nullableString($data['started_at'] ?? null),
                self::nullableInt($data['started_by'] ?? null),
                self::nullableString($data['completed_at'] ?? null),
                self::nullableInt($data['completed_by'] ?? null),
            );
        } catch (ValueError) {
            return self::corruptState();
        }
    }

    /** @return array<string, int|string|null> */
    public function toArray(): array
    {
        return [
            'version' => $this->version,
            'status' => $this->status->value,
            'current_step' => $this->currentStep->value,
            'intro_acknowledged_at' => $this->introAcknowledgedAt,
            'intro_acknowledged_by' => $this->introAcknowledgedBy,
            'started_at' => $this->startedAt,
            'started_by' => $this->startedBy,
            'completed_at' => $this->completedAt,
            'completed_by' => $this->completedBy,
        ];
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR);
    }

    public function isIncomplete(): bool
    {
        return $this->corrupt || $this->status !== AllianceSetupStatus::Completed;
    }

    public function introAcknowledged(): bool
    {
        return $this->introAcknowledgedAt !== null;
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function nullableInt(mixed $value): ?int
    {
        return is_int($value) || (is_string($value) && ctype_digit($value)) ? (int) $value : null;
    }
}
