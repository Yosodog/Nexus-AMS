<?php

namespace App\Models;

use App\GraphQL\Models\Alliance as AllianceGraphQL;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Alliance extends Model
{
    public $table = 'alliances';

    protected $guarded = [];

    /**
     * Create or update an Alliance model from GraphQL Alliance data.
     *
     * @param  AllianceGraphQL  $graphQLAllianceModel  - The GraphQL alliance data
     */
    public static function updateFromAPI(AllianceGraphQL $graphQLAllianceModel, bool $withNations = true): Alliance
    {
        // Extract alliance data
        $allianceData = collect((array) $graphQLAllianceModel)->only([
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
            'rank',
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
     * @return mixed
     */
    public static function getById(int $id)
    {
        return self::where('id', $id)->firstOrFail();
    }

    /**
     * @return HasMany
     */
    public function nations()
    {
        return $this->hasMany(Nation::class, 'alliance_id');
    }
}
