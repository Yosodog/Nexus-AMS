<?php

namespace App\Jobs;

use App\Events\NationAllianceChanged;
use App\GraphQL\Models\Nation as GraphQLNationModel;
use App\Models\Nation;
use App\Services\BeigeAlertService;
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
    public function handle(BeigeAlertService $beigeAlertService): void
    {
        try {
            foreach ($this->nationsData as $nationData) {
                $nationModel = new GraphQLNationModel;
                $nationModel->buildWithJSON((object) $nationData);

                $existingNation = Nation::withTrashed()->find($nationModel->id);
                $oldAllianceId = $existingNation?->alliance_id;
                $oldAlliancePosition = $existingNation?->alliance_position;
                $previousBeigeTurns = $existingNation?->beige_turns;

                $updatedNation = null;

                try {
                    // Attempt to update or create the nation
                    $updatedNation = Nation::updateFromAPI($nationModel);
                } catch (UniqueConstraintViolationException $e) {
                    // Check if the nation exists but is soft deleted
                    $trashedNation = Nation::withTrashed()->find($nationModel->id);

                    if ($trashedNation && $trashedNation->trashed()) {
                        // Restore the soft-deleted nation
                        $trashedNation->restore();

                        // Retry the update now that the model is active
                        $updatedNation = Nation::updateFromAPI($nationModel);
                    } else {
                        // Re-throw the exception if it's not caused by a soft-deleted record
                        throw $e;
                    }
                }

                if ($updatedNation && $existingNation && $oldAllianceId !== $updatedNation->alliance_id) {
                    event(new NationAllianceChanged(
                        nation: $updatedNation,
                        oldAllianceId: $oldAllianceId,
                        oldAlliancePosition: $oldAlliancePosition,
                        newAllianceId: $updatedNation->alliance_id,
                        newAlliancePosition: $updatedNation->alliance_position
                    ));
                }

                if ($updatedNation && $existingNation) {
                    $beigeAlertService->maybeDispatchEarlyExitAlert(
                        nationId: (int) $updatedNation->id,
                        allianceId: $updatedNation->alliance_id,
                        previousBeigeTurns: (int) $previousBeigeTurns,
                        currentBeigeTurns: (int) $updatedNation->beige_turns,
                    );
                }
            }
        } catch (Exception $e) {
            Log::error('Failed to update nations', ['error' => $e->getMessage()]);
        }
    }
}
