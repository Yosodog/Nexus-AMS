<?php

namespace App\Jobs;

use App\GraphQL\Models\Nation as GraphQLNationModel;
use App\Models\Nations;
use App\Models\Cities;
use App\Models\NationResources;
use App\Models\NationMilitary;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class UpdateNationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public array $nationsData;

    /**
     * Create a new job instance.
     */
    public function __construct(array $nationsData)
    {
        $this->nationsData = $nationsData;
    }

    /**
     * Execute the job.
     */
    public function handle(DatabaseManager $db)
    {
        try {
            $nationRecords = [];
            $resourceRecords = [];
            $militaryRecords = [];
            $cityRecords = [];

            foreach ($this->nationsData as $nationData) {
                $nationModel = new GraphQLNationModel();
                $nationModel->buildWithJSON((object) $nationData);

                $nationRecords[] = Nations::prepareNationData($nationModel);

                if (!is_null($nationModel->money)) {
                    $resourceRecords[] = Nations::prepareResourceData($nationModel);
                }
                if (!is_null($nationModel->soldiers)) {
                    $militaryRecords[] = Nations::prepareMilitaryData($nationModel);
                }
                if (!is_null($nationModel->cities)) {
                    foreach ($nationModel->cities as $city) {
                        $cityRecords[] = Cities::prepareCityData($city, $nationModel->id);
                    }
                }
            }

            $db->transaction(function () use ($nationRecords, $resourceRecords, $militaryRecords, $cityRecords) {
                if (!empty($nationRecords)) {
                    Nations::upsert($nationRecords, ['id'], array_keys($nationRecords[0]));
                }

                if (!empty($resourceRecords)) {
                    NationResources::upsert($resourceRecords, ['nation_id'], array_keys($resourceRecords[0]));
                }

                if (!empty($militaryRecords)) {
                    NationMilitary::upsert($militaryRecords, ['nation_id'], array_keys($militaryRecords[0]));
                }

                if (!empty($cityRecords)) {
                    Cities::upsert($cityRecords, ['id'], array_keys($cityRecords[0]));
                }
            });

        } catch (Exception $e) {
            Log::error("Failed to batch update nations and cities", ['error' => $e->getMessage()]);
        }
    }
}