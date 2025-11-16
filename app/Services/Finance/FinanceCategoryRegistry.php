<?php

namespace App\Services\Finance;

use InvalidArgumentException;

final class FinanceCategoryRegistry
{
    /**
     * @var array<string, array<string, mixed>>
     */
    private array $categories;

    public function __construct()
    {
        $this->categories = config('finance.categories', []);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        return $this->categories;
    }

    /**
     * @return array<string, mixed>
     */
    public function get(string $key): array
    {
        if (! $this->exists($key)) {
            throw new InvalidArgumentException("Unknown finance category [{$key}]");
        }

        return $this->categories[$key];
    }

    public function exists(string $key): bool
    {
        return array_key_exists($key, $this->categories);
    }

    public function label(string $key): string
    {
        return (string) ($this->get($key)['label'] ?? $key);
    }

    public function color(string $key): string
    {
        return (string) ($this->get($key)['color'] ?? 'secondary');
    }
}
