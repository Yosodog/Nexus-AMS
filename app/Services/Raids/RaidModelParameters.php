<?php

namespace App\Services\Raids;

use App\Models\RaidModelParameter;

/**
 * Request-scoped reader for calibrated raid model parameters.
 */
final class RaidModelParameters
{
    /** @var array<string, array<string, mixed>>|null */
    private ?array $values = null;

    /**
     * @return array<string, mixed>
     */
    public function get(string $key): array
    {
        $this->values ??= RaidModelParameter::query()
            ->get(['key', 'value'])
            ->mapWithKeys(fn (RaidModelParameter $parameter): array => [
                $parameter->key => is_array($parameter->value) ? $parameter->value : [],
            ])
            ->all();

        return $this->values[$key] ?? [];
    }

    public function forget(): void
    {
        $this->values = null;
    }
}
