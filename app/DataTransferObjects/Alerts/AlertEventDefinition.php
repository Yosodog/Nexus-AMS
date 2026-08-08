<?php

namespace App\DataTransferObjects\Alerts;

use App\Enums\AlertAudience;
use App\Enums\AlertDestinationKind;
use App\Enums\AlertSensitivity;
use App\Enums\AlertSeverity;

final readonly class AlertEventDefinition
{
    /**
     * @param  list<AlertDestinationKind>  $allowedDestinations
     * @param  list<string>  $payloadKeys
     */
    public function __construct(
        public string $key,
        public int $schemaVersion,
        public AlertAudience $audience,
        public AlertSensitivity $sensitivity,
        public AlertSeverity $severity,
        public array $allowedDestinations,
        public string $templateKey,
        public string $filterType,
        public array $payloadKeys,
        public string $stalePolicy,
        public ?int $staleAfterMinutes = null,
        public ?string $requiredPermission = null,
        public bool $mayBypassQuietHours = false,
    ) {}

    public function allows(AlertDestinationKind $destination): bool
    {
        return in_array($destination, $this->allowedDestinations, true);
    }
}
