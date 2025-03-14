<?php

namespace App\Models;

use App\GraphQL\Models\Alliance;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Alliances extends Model
{
    public $table = "alliances";
    protected $guarded = [];

    /**
     * Create or update an Alliance model from GraphQL Alliance data.
     *
     * @param Alliance $graphQLAllianceModel - The GraphQL alliance data
     * @return Alliances
     */
    public static function updateFromAPI(Alliance $graphQLAllianceModel, bool $withNations = true): Alliances
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
                Nations::updateFromAPI($nationData);
            }
        }

        return $alliance;
    }

    /**
     * @param int $id
     * @return mixed
     */
    public static function getById(int $id)
    {
        return self::where("id", $id)->firstOrFail();
    }

    /**
     * @return HasMany
     */
    public function nations()
    {
        return $this->hasMany(Nations::class, "alliance_id");
    }
}
