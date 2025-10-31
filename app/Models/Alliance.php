<?php

namespace App\Models;

use App\AutoSync\Concerns\AutoSyncsWithPoliticsAndWar;
use App\AutoSync\Contracts\SyncableWithPoliticsAndWar;
use App\AutoSync\SyncDefinition;
use App\GraphQL\Models\Alliance as AllianceGraphQL;
use App\Services\AllianceQueryService;
use App\Services\GraphQLQueryBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Alliance extends Model implements SyncableWithPoliticsAndWar
{
    use AutoSyncsWithPoliticsAndWar;

    public $table = "alliances";
    protected $guarded = [];

    /**
     * Create or update an Alliance model from GraphQL Alliance data.
     *
     * @param AllianceGraphQL $graphQLAllianceModel
     * @param bool $withNations
     * @return Alliance
     */
    public static function updateFromAPI(AllianceGraphQL $graphQLAllianceModel, bool $withNations = true): Alliance
    {
        // Extract alliance data
        $allianceData = collect((array)$graphQLAllianceModel)->only([
            'id',
            'name',
            'acronym',
            'score',
            'color',
            'average_score',
            'accept_members',
            'flag',
            'forum_link',
            'discord_link',
            'wiki_link',
            'rank'
        ])->toArray();

        // Use `updateOrCreate` to handle both creation and update
        $alliance = self::updateOrCreate(['id' => $graphQLAllianceModel->id], $allianceData);

        // Check if nations data is included and update/create nations
        if (isset($graphQLAllianceModel->nations) && $withNations) {
            foreach ($graphQLAllianceModel->nations as $nationData) {
                Nation::updateFromAPI($nationData);
            }
        }

        return $alliance;
    }

    /**
     * Retrieve an alliance by its identifier.
     *
     * @param int $id
     * @return mixed
     */
    public static function getById(int $id)
    {
        return self::where("id", $id)->firstOrFail();
    }

    /**
     * Retrieve member nations for the alliance.
     *
     * @return HasMany
     */
    public function nations()
    {
        return $this->hasMany(Nation::class, "alliance_id");
    }

    /**
     * Describe how to synchronize alliances from Politics & War.
     *
     * @return SyncDefinition
     */
    public static function getAutoSyncDefinition(): SyncDefinition
    {
        $staleAfter = config('pw-sync.staleness.' . self::class);

        return new SyncDefinition(
            self::class,
            'id',
            function (array $ids, array $context = []) {
                $ids = array_values(array_unique(array_map('intval', $ids)));

                if (empty($ids)) {
                    return [];
                }

                $arguments = [
                    'id' => count($ids) === 1
                        ? $ids[0]
                        : GraphQLQueryBuilder::literal('[' . implode(', ', $ids) . ']'),
                ];

                $withMembers = $context['include_nations'] ?? true;

                if ($withMembers) {
                    return AllianceQueryService::getMultipleAlliancesWithMembers($ids);
                }

                return AllianceQueryService::getMultipleAlliances(
                    $arguments,
                    max(1, min(count($ids), config('pw-sync.chunk_size', 100))),
                    false,
                    false
                );
            },
            function ($record, array $context = []) {
                return self::updateFromAPI($record, (bool) ($context['include_nations'] ?? true));
            },
            $staleAfter,
            ['name']
        );
    }
}
