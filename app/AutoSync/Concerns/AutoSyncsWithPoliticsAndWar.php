<?php

namespace App\AutoSync\Concerns;

use App\AutoSync\Eloquent\AutoSyncBuilder;
use App\AutoSync\Eloquent\Relations\AutoSyncHasMany;
use App\AutoSync\Eloquent\Relations\AutoSyncHasOne;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;

trait AutoSyncsWithPoliticsAndWar
{
    /**
     * Create a builder that supports automatic syncing behaviour.
     *
     * @param \Illuminate\Database\Query\Builder $query
     * @return EloquentBuilder
     */
    public function newEloquentBuilder($query): EloquentBuilder
    {
        return new AutoSyncBuilder($query);
    }

    /**
     * Instantiate a HasOne relation wrapped with auto-sync support.
     *
     * @param EloquentBuilder $query
     * @param Model $parent
     * @param string $foreignKey
     * @param string $localKey
     * @return AutoSyncHasOne
     */
    protected function newHasOne(EloquentBuilder $query, Model $parent, $foreignKey, $localKey)
    {
        return new AutoSyncHasOne($query, $parent, $foreignKey, $localKey);
    }

    /**
     * Instantiate a HasMany relation wrapped with auto-sync support.
     *
     * @param EloquentBuilder $query
     * @param Model $parent
     * @param string $foreignKey
     * @param string $localKey
     * @return AutoSyncHasMany
     */
    protected function newHasMany(EloquentBuilder $query, Model $parent, $foreignKey, $localKey)
    {
        return new AutoSyncHasMany($query, $parent, $foreignKey, $localKey);
    }
}
