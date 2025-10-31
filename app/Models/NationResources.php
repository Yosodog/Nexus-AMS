<?php

namespace App\Models;

use App\AutoSync\Concerns\AutoSyncsWithPoliticsAndWar;
use App\AutoSync\Contracts\SyncableWithPoliticsAndWar;
use App\AutoSync\SyncDefinition;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class NationResources extends Model implements SyncableWithPoliticsAndWar
{
    use AutoSyncsWithPoliticsAndWar;
    use SoftDeletes;
    protected $table = "nation_resources";

    protected $guarded = [];

    /**
     * Relationship to the Nation model.
     * Each NationResources record belongs to a single Nation.
     *
     * @return BelongsTo
     */
    public function nation()
    {
        return $this->belongsTo(Nation::class);
    }

    /**
     * Describe how to synchronize nation resource snapshots from Politics & War.
     *
     * @return SyncDefinition
     */
    public static function getAutoSyncDefinition(): SyncDefinition
    {
        return new SyncDefinition(
            self::class,
            'nation_id',
            function (array $ids) {
                return Nation::getAutoSyncDefinition()->fetchRecords($ids);
            },
            function ($record) {
                $nation = Nation::updateFromAPI($record);

                return $nation->resources()->withTrashed()->first();
            }
        );
    }
}
