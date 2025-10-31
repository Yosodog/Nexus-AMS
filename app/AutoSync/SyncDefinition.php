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
        public readonly ?int $staleAfterHours = null
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
}
