<?php

namespace App\AutoSync;

use App\AutoSync\Contracts\SyncableWithPoliticsAndWar;
use App\Services\PWHealthService;
use Exception;
use Illuminate\Contracts\Cache\Lock as CacheLock;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class AutoSyncManager
{
    protected array $definitions = [];

    protected array $syncedInRequest = [];

    protected array $staleRefreshAttempted = [];

    protected array $relationAttempts = [];

    public function __construct(protected PWHealthService $healthService)
    {
    }

    /**
     * Resolve the sync definition for the provided model class.
     *
     * @param class-string<Model&SyncableWithPoliticsAndWar> $modelClass
     * @return SyncDefinition
     */
    public function definitionFor(string $modelClass): SyncDefinition
    {
        if (! isset($this->definitions[$modelClass])) {
            if (! is_subclass_of($modelClass, SyncableWithPoliticsAndWar::class)) {
                throw new Exception(sprintf('Model %s is not syncable.', $modelClass));
            }

            $this->definitions[$modelClass] = $modelClass::getAutoSyncDefinition();
        }

        return $this->definitions[$modelClass];
    }

    /**
     * Refresh a model from the API when it is considered stale.
     *
     * @param Model $model
     * @return Model
     */
    public function ensureFreshModel(Model $model): Model
    {
        $definition = $this->definitionFor($model::class);

        if (! $definition->staleAfterHours) {
            return $model;
        }

        $updatedAt = $model->getAttribute('updated_at');

        if (! $updatedAt) {
            return $model;
        }

        $key = $this->makeModelKey($model::class, $this->extractIdentifier($model, $definition));

        if (isset($this->staleRefreshAttempted[$key])) {
            return $model;
        }

        if ($updatedAt->greaterThan(now()->subHours($definition->staleAfterHours))) {
            return $model;
        }

        $this->staleRefreshAttempted[$key] = true;

        $identifier = $this->extractIdentifier($model, $definition);

        if ($identifier === null) {
            return $model;
        }

        $this->sync($model::class, [$identifier], ['force' => true]);

        // Reload from the database to ensure we return the refreshed instance.
        $refreshed = $this->reloadModel($model, $definition, $identifier);

        return $refreshed ?? $model;
    }

    /**
     * Refresh every model in the provided collection.
     *
     * @param EloquentCollection $collection
     * @return EloquentCollection
     */
    public function ensureFreshCollection(EloquentCollection $collection): EloquentCollection
    {
        foreach ($collection as $index => $model) {
            if ($model instanceof Model) {
                $collection->put($index, $this->ensureFreshModel($model));
            }
        }

        return $collection;
    }

    /**
     * Trigger a synchronization for a set of identifiers.
     *
     * @param class-string<Model&SyncableWithPoliticsAndWar> $modelClass
     * @param array<int, string|int> $ids
     * @param array<string, mixed> $context
     * @return void
     */
    public function sync(string $modelClass, array $ids, array $context = []): void
    {
        if ($this->healthService->isUp() === false) {
            return;
        }

        $definition = $this->definitionFor($modelClass);
        $ids = array_values(array_unique(array_map('strval', array_filter($ids, static fn($id) => ! is_null($id)))));

        if (empty($ids)) {
            return;
        }

        $force = $context['force'] ?? false;

        if (! $force) {
            $ids = array_diff($ids, $this->syncedInRequest[$modelClass] ?? []);
        }

        if (empty($ids)) {
            return;
        }

        $ids = array_filter($ids, function ($id) use ($modelClass) {
            return ! Cache::has($this->missingCacheKey($modelClass, $id));
        });

        if (empty($ids)) {
            return;
        }

        $chunkSize = (int) config('pw-sync.chunk_size', 100);

        foreach (array_chunk($ids, $chunkSize) as $chunk) {
            // Synchronize in batches to honour API limits and lock granularity.
            $this->syncChunk($definition, $chunk, $context);
        }
    }

    /**
     * Inspect relation results and re-run the query when related records are missing.
     *
     * @param Relation $relation
     * @param mixed $results
     * @param callable $retry
     * @return mixed
     */
    public function afterRelationResults(Relation $relation, mixed $results, callable $retry)
    {
        if ($this->healthService->isUp() === false) {
            return $results;
        }

        $shouldAttempt = $this->shouldAttemptRelationSync($relation, $results);

        if (! $shouldAttempt) {
            return $this->maybeRefreshRelationResults($results);
        }

        $key = $this->relationAttemptKey($relation);

        if (isset($this->relationAttempts[$key])) {
            return $this->maybeRefreshRelationResults($results);
        }

        $this->relationAttempts[$key] = true;

        $this->performRelationSync($relation);

        $refreshed = $retry();

        return $this->maybeRefreshRelationResults($refreshed);
    }

    /**
     * Synchronize a single batch of identifiers under an acquired lock set.
     *
     * @param SyncDefinition $definition
     * @param array<int, string|int> $ids
     * @param array<string, mixed> $context
     * @return void
     */
    protected function syncChunk(SyncDefinition $definition, array $ids, array $context = []): void
    {
        $locks = $this->acquireLocks($definition->modelClass, $ids);
        $lockedIds = array_keys(array_filter($locks));

        if (empty($lockedIds)) {
            return;
        }

        try {
            $records = $definition->fetchRecords($lockedIds, $context);
            $synced = [];

            foreach ($records as $record) {
                $model = $definition->upsertRecord($record, $context);

                if ($model instanceof Model) {
                    $identifier = $this->extractIdentifier($model, $definition);

                    if ($identifier !== null) {
                        $synced[] = (string) $identifier;
                    }
                }
            }

            $synced = array_unique($synced);
            $this->syncedInRequest[$definition->modelClass] = array_unique(array_merge(
                array_map('strval', $this->syncedInRequest[$definition->modelClass] ?? []),
                $synced
            ));

            $lockedKeys = array_map('strval', $lockedIds);
            $missing = array_diff($lockedKeys, $synced);

            foreach ($missing as $id) {
                // Cache the negative lookup so subsequent requests avoid redundant API calls.
                Cache::put($this->missingCacheKey($definition->modelClass, $id), true, now()->addMinutes(60));
            }
        } catch (Throwable $exception) {
            Log::warning('Failed auto-sync batch.', [
                'model' => $definition->modelClass,
                'ids' => $ids,
                'exception' => $exception,
            ]);
        } finally {
            foreach ($locks as $lock) {
                if ($lock instanceof CacheLock) {
                    $lock->release();
                }
            }
        }
    }

    /**
     * Acquire a set of cache locks to avoid duplicate sync work across requests.
     *
     * @param string $modelClass
     * @param array<int, string|int> $ids
     * @return array<string|int, CacheLock|null>
     */
    protected function acquireLocks(string $modelClass, array $ids): array
    {
        $locks = [];

        foreach ($ids as $id) {
            $lock = Cache::lock($this->lockKey($modelClass, $id), 30);

            if ($lock->get()) {
                $locks[$id] = $lock;
            }
        }

        return $locks;
    }

    /**
     * Build the cache key for the distributed lock.
     *
     * @param string $modelClass
     * @param mixed $id
     * @return string
     */
    protected function lockKey(string $modelClass, mixed $id): string
    {
        return 'pw-sync:lock:'.md5($modelClass.':'.$id);
    }

    /**
     * Build the cache key that tracks missing entities.
     *
     * @param string $modelClass
     * @param mixed $id
     * @return string
     */
    protected function missingCacheKey(string $modelClass, mixed $id): string
    {
        return 'pw-sync:missing:'.md5($modelClass.':'.$id);
    }

    /**
     * Extract the identifier column from the given model.
     *
     * @param Model $model
     * @param SyncDefinition $definition
     * @return mixed
     */
    protected function extractIdentifier(Model $model, SyncDefinition $definition): mixed
    {
        $column = $definition->identifierColumn;

        if ($column === $model->getKeyName()) {
            return $model->getKey();
        }

        return $model->getAttribute($column);
    }

    /**
     * Reload the model instance by its identifier after a sync completes.
     *
     * @param Model $model
     * @param SyncDefinition $definition
     * @param mixed $identifier
     * @return Model|null
     */
    protected function reloadModel(Model $model, SyncDefinition $definition, mixed $identifier): ?Model
    {
        $query = $model->newQuery();

        if ($definition->identifierColumn === $model->getKeyName()) {
            $query->whereKey($identifier);
        } else {
            $query->where($definition->identifierColumn, $identifier);
        }

        return $query->first();
    }

    /**
     * Build a compound key for per-request caches.
     *
     * @param string $modelClass
     * @param mixed $identifier
     * @return string
     */
    protected function makeModelKey(string $modelClass, mixed $identifier): string
    {
        return $modelClass.':'.$identifier;
    }

    /**
     * Determine whether a relation should attempt to back-fill missing data.
     *
     * @param Relation $relation
     * @param mixed $results
     * @return bool
     */
    protected function shouldAttemptRelationSync(Relation $relation, mixed $results): bool
    {
        $parent = $relation->getParent();
        $related = $relation->getRelated();

        $isCollection = $results instanceof EloquentCollection;

        if ($parent instanceof \App\Models\Alliance && $related instanceof \App\Models\Nation) {
            if (! $isCollection) {
                return false;
            }

            return $results->isEmpty();
        }

        if ($parent instanceof \App\Models\Nation) {
            if ($related instanceof \App\Models\City) {
                if (! $isCollection) {
                    return false;
                }

                $expected = $parent->getAttribute('num_cities');

                if (is_null($expected)) {
                    return $results->isEmpty();
                }

                return $results->count() < $expected;
            }

            if ($related instanceof \App\Models\NationResources || $related instanceof \App\Models\NationMilitary) {
                return $results === null;
            }
        }

        return false;
    }

    /**
     * Trigger the owning model sync for related data.
     *
     * @param Relation $relation
     * @return void
     */
    protected function performRelationSync(Relation $relation): void
    {
        $parent = $relation->getParent();
        $related = $relation->getRelated();

        if ($parent instanceof \App\Models\Alliance && $related instanceof \App\Models\Nation) {
            $this->sync($parent::class, [$parent->getKey()], ['include_nations' => true]);

            return;
        }

        if ($parent instanceof \App\Models\Nation) {
            $this->sync(\App\Models\Nation::class, [$parent->getKey()], ['include_cities' => true]);
        }
    }

    /**
     * Provide a stable key for relation attempt tracking within a request.
     *
     * @param Relation $relation
     * @return string
     */
    protected function relationAttemptKey(Relation $relation): string
    {
        return spl_object_hash($relation);
    }

    /**
     * Refresh the provided relation results if they are models or collections.
     *
     * @param mixed $results
     * @return mixed
     */
    protected function maybeRefreshRelationResults(mixed $results)
    {
        if ($results instanceof EloquentCollection) {
            return $this->ensureFreshCollection($results);
        }

        if ($results instanceof Model) {
            return $this->ensureFreshModel($results);
        }

        return $results;
    }

    /**
     * Extract identifiers from the underlying query builder "where" clauses.
     *
     * @param array<int, array<string, mixed>> $wheres
     * @param string $identifierColumn
     * @return array<int, mixed>
     */
    public function extractIdsFromQuery(array $wheres, string $identifierColumn): array
    {
        $ids = [];

        foreach ($wheres as $where) {
            if ($where['type'] === 'Basic') {
                $column = $this->stripColumn($where['column']);

                if ($column === $identifierColumn && in_array($where['operator'], ['=', '=='])) {
                    $ids[] = $where['value'];
                }
            }

            if ($where['type'] === 'In') {
                $column = $this->stripColumn($where['column']);

                if ($column === $identifierColumn) {
                    $ids = array_merge($ids, $where['values']);
                }
            }

            if ($where['type'] === 'Nested' && isset($where['query']->wheres)) {
                $ids = array_merge($ids, $this->extractIdsFromQuery($where['query']->wheres, $identifierColumn));
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Strip table prefixes from a column name.
     *
     * @param string $column
     * @return string
     */
    protected function stripColumn(string $column): string
    {
        return Str::contains($column, '.') ? Str::after($column, '.') : $column;
    }
}
