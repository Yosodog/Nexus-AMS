<?php

namespace App\AutoSync\Contracts;

use App\AutoSync\SyncDefinition;

interface SyncableWithPoliticsAndWar
{
    /**
     * Provide the auto-sync definition describing how to refresh the model.
     *
     * @return SyncDefinition
     */
    public static function getAutoSyncDefinition(): SyncDefinition;
}
