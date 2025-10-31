<?php

namespace App\AutoSync\Eloquent;

use App\AutoSync\AutoSyncManager;
use App\AutoSync\SyncDefinition;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

class AutoSyncBuilder extends Builder
{
    protected array $syncAttempts = [];

    /**
     * Resolve the auto-sync manager from the container.
     *
     * @return AutoSyncManager
     */
    protected function manager(): AutoSyncManager
    {
        return app(AutoSyncManager::class);
    }

    /**
     * Retrieve the sync definition for the current model.
     *
     * @return SyncDefinition
     */
    protected function definition(): SyncDefinition
    {
        return $this->manager()->definitionFor($this->model::class);
    }

    /**
     * Find a model by its primary key and back-fill it when missing.
     *
     * @param mixed $id
     * @param array<int, string> $columns
     * @return Model|EloquentCollection|null
     */
    public function find($id, $columns = ['*'])
    {
        if (is_array($id)) {
            return $this->findMany($id, $columns);
        }

        $result = parent::find($id, $columns);

        if ($result instanceof Model) {
            return $this->manager()->ensureFreshModel($result);
        }

        if ($result instanceof EloquentCollection) {
            return $this->findMany($id, $columns);
        }

        $ids = array_map('strval', Arr::wrap($id));

        if ($this->markAttempt($ids)) {
            return $result;
        }

        $this->manager()->sync($this->model::class, $ids);

        $result = parent::find($id, $columns);

        if ($result instanceof Model) {
            return $this->manager()->ensureFreshModel($result);
        }

        return $result;
    }

    /**
     * Find multiple models by their primary keys and fetch missing entries.
     *
     * @param mixed $ids
     * @param array<int, string> $columns
     * @return EloquentCollection
     */
    public function findMany($ids, $columns = ['*'])
    {
        $ids = array_map('strval', Arr::wrap($ids));
        $results = parent::findMany($ids, $columns);

        $results = $this->manager()->ensureFreshCollection($results);
        $foundKeys = array_map('strval', $results->modelKeys());
        $missing = array_diff($ids, $foundKeys);

        if (! empty($missing) && ! $this->markAttempt($missing)) {
            $this->manager()->sync($this->model::class, $missing);
            $results = parent::findMany($ids, $columns);
            $results = $this->manager()->ensureFreshCollection($results);
        }

        return $results;
    }

    /**
     * Retrieve the first record from the query, syncing if the expected ID is missing.
     *
     * @param array<int, string> $columns
     * @return Model|object|static|null
     */
    public function first($columns = ['*'])
    {
        $result = parent::first($columns);

        if ($result instanceof Model) {
            return $this->manager()->ensureFreshModel($result);
        }

        $ids = $this->resolveIdsFromQuery();

        if (! empty($ids) && ! $this->markAttempt($ids)) {
            $this->manager()->sync($this->model::class, $ids);
            $result = parent::first($columns);

            if ($result instanceof Model) {
                return $this->manager()->ensureFreshModel($result);
            }
        }

        return $result;
    }

    /**
     * Run the query as a "select" statement with auto-sync back-filling for missing rows.
     *
     * @param array<int, string> $columns
     * @return EloquentCollection
     */
    public function get($columns = ['*'])
    {
        $results = parent::get($columns);
        $results = $this->manager()->ensureFreshCollection($results);

        $expectedIds = array_map('strval', $this->expectedIds());

        if (! empty($expectedIds)) {
            $found = array_map('strval', $results->pluck($this->definition()->identifierColumn)->filter()->all());
            $missing = array_diff($expectedIds, $found);

            if (! empty($missing) && ! $this->markAttempt($missing)) {
                $this->manager()->sync($this->model::class, $missing);
                $results = parent::get($columns);
                $results = $this->manager()->ensureFreshCollection($results);
            }
        }

        return $results;
    }

    /**
     * Determine which identifiers the current query is constraining.
     *
     * @return array<int, mixed>
     */
    protected function expectedIds(): array
    {
        $definition = $this->definition();
        $wheres = $this->getQuery()->wheres ?? [];

        return $this->manager()->extractIdsFromQuery($wheres, $definition->identifierColumn);
    }

    /**
     * Extract IDs from the underlying query constraints.
     *
     * @return array<int, mixed>
     */
    protected function resolveIdsFromQuery(): array
    {
        return $this->expectedIds();
    }

    /**
     * Mark a sync attempt so we avoid infinite recursion when data is missing.
     *
     * @param array<int, string> $ids
     * @return bool
     */
    protected function markAttempt(array $ids): bool
    {
        $ids = array_map('strval', $ids);
        sort($ids);
        $key = md5($this->model::class.':'.implode(',', $ids));

        if (isset($this->syncAttempts[$key])) {
            return true;
        }

        $this->syncAttempts[$key] = true;

        return false;
    }
}
