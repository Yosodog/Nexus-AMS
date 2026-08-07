<?php

namespace App\Services\StaffWorkQueue;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final readonly class StaffWorkQueueFilterSet
{
    public const URGENCIES = ['urgent', 'attention', 'routine'];

    public function __construct(
        public ?string $search = null,
        public ?string $type = null,
        public ?string $urgency = null,
        public ?string $owner = null,
        public string $sort = 'age',
        public string $direction = 'desc',
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @param  list<string>  $allowedTypes
     */
    public static function fromArray(array $input, array $allowedTypes): self
    {
        $validated = Validator::make($input, self::rules($allowedTypes))->validate();

        return new self(
            search: self::nullableString($validated['q'] ?? null),
            type: self::nullableString($validated['type'] ?? null),
            urgency: self::nullableString($validated['urgency'] ?? null),
            owner: self::nullableString($validated['owner'] ?? null),
            sort: (string) ($validated['sort'] ?? 'age'),
            direction: (string) ($validated['direction'] ?? 'desc'),
        );
    }

    /**
     * @param  list<string>  $allowedTypes
     * @return array<string, array<int, mixed>>
     */
    public static function rules(array $allowedTypes): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', 'string', Rule::in($allowedTypes)],
            'urgency' => ['nullable', 'string', Rule::in(self::URGENCIES)],
            'owner' => ['nullable', 'string', 'max:100', 'regex:/\A[a-z0-9:_-]+\z/i'],
            'sort' => ['nullable', 'string', Rule::in(['age'])],
            'direction' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
        ];
    }

    public function hasActiveFilters(): bool
    {
        return $this->search !== null
            || $this->type !== null
            || $this->urgency !== null
            || $this->owner !== null;
    }

    /**
     * @return array{q?: string, type?: string, urgency?: string, owner?: string, sort: string, direction: string}
     */
    public function toArray(): array
    {
        return array_filter([
            'q' => $this->search,
            'type' => $this->type,
            'urgency' => $this->urgency,
            'owner' => $this->owner,
            'sort' => $this->sort,
            'direction' => $this->direction,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    private static function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
