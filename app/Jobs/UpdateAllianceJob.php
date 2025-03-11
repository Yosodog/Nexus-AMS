<?php

namespace App\Jobs;

use App\Models\Alliances;
use Exception;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class UpdateAllianceJob implements ShouldQueue
{
    use Queueable;

    public array $alliancesData;

    public array $skips = [
        "date",
        "money",
        "money",
        "coal",
        "oil",
        "uranium",
        "iron",
        "bauxite",
        "lead",
        "gasoline",
        "munitions",
        "steel",
        "aluminum",
        "food",
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
                $allianceModel = Alliances::getById($allianceData['id']);

                foreach ($allianceData as $key => $data) {
                    if (in_array($key, $this->skips)) { // Skip stuff that we don't store
                        continue;
                    }

                    $allianceModel->$key = $data ?? '';
                }

                $allianceModel->save();
            }
        } catch (Exception $e) {
            Log::error("Failed to update alliances", ['error' => $e->getMessage()]);
        }
    }
}
