<?php

namespace App\Jobs;

use App\GraphQL\Models\Nation as GraphQLNationModel;
use App\Models\Nation;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class UpdateNationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public array $nationsData;

    public $timeout = 5;

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
                $nationModel = new GraphQLNationModel;
                $nationModel->buildWithJSON((object) $nationData);

                try {
                    // Attempt to update or create the nation
                    Nation::updateFromAPI($nationModel);
                } catch (UniqueConstraintViolationException $e) {
                    // Check if the nation exists but is soft deleted
                    $trashedNation = Nation::withTrashed()->find($nationModel->id);

                    if ($trashedNation && $trashedNation->trashed()) {
                        // Restore the soft-deleted nation
                        $trashedNation->restore();

                        // Retry the update now that the model is active
                        Nation::updateFromAPI($nationModel);
                    } else {
                        // Re-throw the exception if it's not caused by a soft-deleted record
                        throw $e;
                    }
                }

            }
        } catch (Exception $e) {
            Log::error('Failed to update nations', ['error' => $e->getMessage()]);
        }
    }
}
