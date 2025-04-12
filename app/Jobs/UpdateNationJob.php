<?php

namespace App\Jobs;

use App\GraphQL\Models\Nation as GraphQLNationModel;
use App\Models\Nation;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
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
    public function handle()
    {
        try {
            foreach ($this->nationsData as $nationData) {
                $nationModel = new GraphQLNationModel();
                $nationModel->buildWithJSON((object)$nationData);

                // Use updateFromAPI() to update or create the nation
                Nation::updateFromAPI($nationModel);
            }
        } catch (Exception $e) {
            Log::error("Failed to update nations", ['error' => $e->getMessage()]);
        }
    }
}