<?php

namespace App\Jobs;

use App\Models\Alliance;
use App\Services\AllianceQueryService;
use Exception;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class UpdateAllianceJob implements ShouldQueue
{
    use Queueable;

    public array $alliancesData;

    public array $skips = [
        'date',
        'money',
        'money',
        'coal',
        'oil',
        'uranium',
        'iron',
        'bauxite',
        'lead',
        'gasoline',
        'munitions',
        'steel',
        'aluminum',
        'food',
    ];

    /**
     * Create a new job instance.
     */
    public function __construct(array $alliancesData)
    {
        $this->alliancesData = $alliancesData;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            foreach ($this->alliancesData as $allianceData) {
                // Get the model from the DB
                $allianceModel = Alliance::getById($allianceData['id']);

                foreach ($allianceData as $key => $data) {
                    if (in_array($key, $this->skips)) { // Skip stuff that we don't store
                        continue;
                    }

                    $allianceModel->$key = $data ?? '';
                }

                $allianceModel->save();
            }
        } catch (ModelNotFoundException $e) {
            // Model is not in the DB for some reason, so let's just create it
            // Now, we have the data for the model... but sometimes that data is not consistent with what we have in the DB
            // So we'll just query and add it as usual lol
            $alliance = AllianceQueryService::getAllianceById($allianceModel->id);
            Alliance::updateFromAPI($alliance);
        } catch (Exception $e) {
            Log::error('Failed to update alliances', ['error' => $e->getMessage()]);
        }
    }
}
