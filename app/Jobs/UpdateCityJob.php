<?php

namespace App\Jobs;

use App\Models\Cities;
use Exception;
use Illuminate\Contracts\Queue\ShouldQueue;
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
                $cityModel = Cities::getById($cityData['id']);

                foreach ($cityData as $key => $data) {
                    if (in_array($key, $this->skips)) { // Skip stuff that we don't store
                        continue;
                    }

                    $cityModel->$key = $data ?? '';
                }

                $cityModel->save();
            }
        } catch (Exception $e) {
            Log::error("Failed to update cities", ['error' => $e->getMessage()]);
        }
    }
}
