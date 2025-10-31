<?php

namespace App\AutoSync\Eloquent\Relations;

use App\AutoSync\AutoSyncManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AutoSyncHasOne extends HasOne
{
    /**
     * @param Builder $query
     * @param Model $parent
     * @param string $foreignKey
     * @param string $localKey
     */
    public function __construct(Builder $query, Model $parent, $foreignKey, $localKey)
    {
        parent::__construct($query, $parent, $foreignKey, $localKey);
    }

    /**
     * Resolve the auto-sync manager that coordinates relation refreshes.
     *
     * @return AutoSyncManager
     */
    protected function manager(): AutoSyncManager
    {
        return app(AutoSyncManager::class);
    }

    /**
     * Retrieve the relation results with automatic re-sync when the data is missing.
     *
     * @return Model|mixed
     */
    public function getResults()
    {
        $results = parent::getResults();

        return $this->manager()->afterRelationResults($this, $results, function () {
            return parent::getResults();
        });
    }

    /**
     * Retrieve eager loaded results with automatic re-sync when necessary.
     *
     * @return \Illuminate\Database\Eloquent\Collection|Model[]|Model|null
     */
    public function getEager()
    {
        $results = parent::getEager();

        return $this->manager()->afterRelationResults($this, $results, function () {
            return parent::getEager();
        });
    }
}
