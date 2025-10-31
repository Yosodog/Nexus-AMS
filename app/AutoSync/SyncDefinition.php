<?php

namespace App\AutoSync;

use Closure;
use Illuminate\Database\Eloquent\Model;

class SyncDefinition
{
    public function __construct(
        public readonly string $modelClass,
        public readonly string $identifierColumn,
        protected readonly Closure $fetcher,
        protected readonly Closure $upserter,
        public readonly ?int $staleAfterHours = null,
        public readonly array $requiredAttributes = [],
        protected readonly ?Closure $contextPreparer = null,
        public readonly array $contextAliases = []
    ) {
    }

    /**
     * Fetch records for the provided identifiers.
     *
     * @param array<int, string|int> $ids
     * @param array<string, mixed> $context
     * @return iterable
     */
    public function fetchRecords(array $ids, array $context = []): iterable
    {
        return ($this->fetcher)($ids, $context);
    }

    /**
     * Upsert a record into the database using the provided callback.
     *
     * @param mixed $record
     * @param array<string, mixed> $context
     * @return Model|null
     */
    public function upsertRecord(mixed $record, array $context = []): ?Model
    {
        return ($this->upserter)($record, $context);
    }

    /**
     * Normalise the sync context before executing a fetch.
     *
     * @param array<int, string|int> $ids
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function prepareContext(array $ids, array $context = []): array
    {
        if ($this->contextPreparer instanceof Closure) {
            return ($this->contextPreparer)($ids, $context);
        }

        return $context;
    }

    /**
     * Determine which additional context signatures should be treated as satisfied.
     *
     * @param array<string, mixed> $context
     * @param callable(array<string, mixed>):string $signatureResolver
     * @return array<int, string>
     */
    public function aliasSignatures(array $context, callable $signatureResolver): array
    {
        if (empty($this->contextAliases)) {
            return [];
        }

        $aliases = [];

        foreach ($this->contextAliases as $alias) {
            $conditions = $alias['when'] ?? [];

            $matches = collect($conditions)->every(function ($value, $key) use ($context) {
                return array_key_exists($key, $context) && $context[$key] === $value;
            });

            if (! $matches) {
                continue;
            }

            $also = $alias['also'] ?? [];

            foreach ($also as $impliedContext) {
                if (! is_array($impliedContext)) {
                    continue;
                }

                $aliases[] = $signatureResolver($impliedContext);
            }
        }

        return array_values(array_unique(array_filter($aliases)));
    }
}
