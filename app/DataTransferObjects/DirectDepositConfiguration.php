<?php

namespace App\DataTransferObjects;

final readonly class DirectDepositConfiguration
{
    public function __construct(
        public int $allianceId,
        public bool $enabled,
        public ?int $taxId,
        public ?int $fallbackTaxId,
        public ?int $offshoreId = null,
        public ?string $unavailableReason = null,
    ) {}

    public function isAvailable(): bool
    {
        return $this->enabled
            && $this->taxId !== null
            && $this->taxId > 0
            && $this->fallbackTaxId !== null
            && $this->fallbackTaxId > 0
            && $this->taxId !== $this->fallbackTaxId
            && $this->unavailableReason === null;
    }
}
