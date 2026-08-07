<?php

namespace App\Services\Applications;

use App\Exceptions\ApplicationException;
use App\Exceptions\PWEntityDoesNotExist;
use App\GraphQL\Models\Nation;
use App\Models\Nation as NationRecord;
use App\Services\NationQueryService;
use Illuminate\Support\Facades\Log;
use Throwable;

class ApplicationNationLookup
{
    /**
     * @param  callable(): Nation  $query
     *
     * @throws ApplicationException
     */
    public function fetchLive(int $nationId, callable $query, string $joinUrl): Nation
    {
        try {
            return $query();
        } catch (PWEntityDoesNotExist) {
            throw new ApplicationException('nation_not_found', 'Nation not found.', 404, context: [
                'join_url' => $joinUrl,
            ]);
        } catch (Throwable $exception) {
            Log::error('Failed to fetch nation for application', [
                'nation_id' => $nationId,
                'error' => $exception->getMessage(),
            ]);

            throw new ApplicationException(
                'nation_lookup_failed',
                'Unable to validate the nation at this time.',
                503
            );
        }
    }

    public function queryNationFromApi(int $nationId): Nation
    {
        return NationQueryService::getNationById($nationId);
    }

    public function findLocalNationSnapshot(int $nationId): ?NationRecord
    {
        return NationRecord::query()
            ->select(['id', 'alliance_id', 'alliance_position', 'alliance_position_id', 'leader_name'])
            ->find($nationId);
    }

    public function mapLocalNation(NationRecord $nation): Nation
    {
        $graphQlNation = new Nation;
        $graphQlNation->id = (int) $nation->id;
        $graphQlNation->leader_name = $nation->leader_name;
        $graphQlNation->alliance_id = $nation->alliance_id !== null ? (int) $nation->alliance_id : null;
        $graphQlNation->alliance_position = $nation->alliance_position;
        $graphQlNation->alliance_position_id = $nation->alliance_position_id !== null
            ? (int) $nation->alliance_position_id
            : null;

        return $graphQlNation;
    }
}
