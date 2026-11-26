<?php

namespace App\Jobs;

use App\Events\NationAllianceChanged;
use App\GraphQL\Models\Nation as GraphQLNationModel;
use App\Models\Nation;
use App\Services\BeigeAlertService;
use App\Services\NationProfitabilityService;
use App\Services\Raids\RaidProfileDirtyMarker;
use App\Services\World\WorldWriteGuard;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class UpdateNationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public array $nationsData;

    public $timeout = 60;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 30];

    /**
     * Create a new job instance.
     */
    public function __construct(array $nationsData)
    {
        $this->nationsData = $nationsData;
    }

    /**
     * Execute the job.
     *
     * Stored nations (and their military rows) for the whole chunk are loaded with one query
     * each. Raid profile dirty marks and snapshot deletions for the nations already written are
     * flushed even when a later record throws, so a retry only replays work.
     */
    public function handle(
        WorldWriteGuard $worldWriteGuard,
        BeigeAlertService $beigeAlertService,
        NationProfitabilityService $profitabilityService,
    ): void {
        $worldWriteGuard->assertCanWrite(Nation::class);

        $processedNationIds = [];
        $snapshotDeletionNationIds = [];

        try {
            $storedNations = $this->loadStoredNations();

            foreach ($this->nationsData as $nationData) {
                $nationModel = new GraphQLNationModel;
                $nationModel->buildWithJSON((object) $nationData);

                $existingNation = $nationModel->id !== null
                    ? $storedNations->get((int) $nationModel->id)
                    : null;
                $oldAllianceId = $existingNation?->alliance_id;
                $oldAlliancePosition = $existingNation?->alliance_position;
                $previousBeigeTurns = $existingNation?->beige_turns;

                $updatedNation = null;

                try {
                    // Attempt to update or create the nation
                    $updatedNation = Nation::updateFromAPI($nationModel, $existingNation);
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

                if ($updatedNation?->exists) {
                    $storedNations->put((int) $updatedNation->id, $updatedNation);
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

                if ($updatedNation) {
                    $processedNationIds[] = (int) $updatedNation->id;

                    if ($profitabilityService->shouldStoreSnapshotForNation($updatedNation)) {
                        RefreshNationProfitabilitySnapshotJob::dispatch((int) $updatedNation->id);
                    } else {
                        $snapshotDeletionNationIds[] = (int) $updatedNation->id;
                    }
                }
            }
        } catch (Throwable $e) {
            Log::error('Failed to update nations from subscription.', [
                'nation_ids' => collect($this->nationsData)->pluck('id')->filter()->take(10)->values()->all(),
                'record_count' => count($this->nationsData),
                'processed_count' => count($processedNationIds),
                'exception_class' => $e::class,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        } finally {
            app(RaidProfileDirtyMarker::class)->mark($processedNationIds);

            if ($snapshotDeletionNationIds !== []) {
                $profitabilityService->deleteStoredSnapshotsForNationIds($snapshotDeletionNationIds);
            }
        }
    }

    /**
     * Load every stored nation in this chunk, soft-deleted ones included, keyed by ID.
     *
     * @return EloquentCollection<int, Nation>
     */
    private function loadStoredNations(): EloquentCollection
    {
        $nationIds = collect($this->nationsData)
            ->map(fn (mixed $record): mixed => is_array($record) ? ($record['id'] ?? null) : null)
            ->filter(fn (mixed $id): bool => is_numeric($id) && (int) $id > 0)
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();

        if ($nationIds->isEmpty()) {
            return new EloquentCollection;
        }

        return Nation::withTrashed()
            ->with('military')
            ->whereIn('id', $nationIds->all())
            ->get()
            ->keyBy('id');
    }
}
