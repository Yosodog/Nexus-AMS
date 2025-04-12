<?php

namespace App\Jobs;

use App\Models\City;
use App\Services\CityQueryService;
use Exception;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class UpdateCityJob implements ShouldQueue
{
    use Queueable;

    public array $citiesData;

    public array $skips = ['nuke_date'];

    /**
     * Create a new job instance.
     */
    public function __construct(array $citiesData)
    {
        $this->citiesData = $citiesData;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            foreach ($this->citiesData as $cityData) {
                // Get the model from the DB
                $cityModel = City::getById($cityData['id']);

                foreach ($cityData as $key => $data) {
                    if (in_array($key, $this->skips)) { // Skip stuff that we don't store
                        continue;
                    }

                    $cityModel->$key = $data ?? '';
                }

                $cityModel->save();
            }
        } catch (ModelNotFoundException $e) {
            // Model is not in the DB for some reason, so let's just create it
            // Now, we have the data for the model... but sometimes that data is not consistent with what we have in the DB
            // So we'll just query and add it as usual lol The nations job does things differently so this is not needed
            $city = CityQueryService::getCityById($cityData['id']);
            City::updateFromAPI($city);
        } catch (Exception $e) {
            Log::error("Failed to update cities", ['error' => $e->getMessage()]);
        }
    }
}
