<?php

namespace App\Jobs;

use App\Models\Nation;
use App\Services\NationQueryService;
use Exception;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class CreateNationJob implements ShouldQueue
{
    use Queueable;

    public array $nationsData;

    /**
     * Create a new job instance.
     */
    public function __construct(array $nationsData)
    {
        $this->nationsData = $nationsData;
    }

    /**
     * The subscription doesn't give us everything we need, so we'll just query the API to get all the info. Weird, but I don't care.
     *
     * @return void
     */
    public function handle(): void
    {
        try {
            foreach ($this->nationsData as $nationData) {
                $nationModel = NationQueryService::getNationById($nationData['id']);

                // Use updateFromAPI() to create the nation
                Nation::updateFromAPI($nationModel);
            }
        } catch (Exception $e) {
            Log::error("Failed to create nations", ['error' => $e->getMessage()]);
        }
    }
}
