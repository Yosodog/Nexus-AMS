<?php

namespace App\Jobs;

use App\Models\War;
use Exception;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class UpdateWarJob implements ShouldQueue
{
    use Queueable, InteractsWithQueue;

    public function __construct(public array $warsData)
    {
    }

    /**
     * @return void
     */
    public function handle(): void
    {
        try {
            foreach ($this->warsData as $warData) {
                // If the war isn't involving our alliance, then skip
                if (!$this->determineIfAllianceWar($warData)) {
                    continue;
                }
                // Convert to stdClass and hydrate the model
                War::updateFromAPI((object)$warData);
            }
        } catch (Exception $e) {
            Log::error('Failed to update wars', ['error' => $e->getMessage()]);
        }
    }

    /**
     * @param array $warData
     * @return bool
     */
    private function determineIfAllianceWar(array $warData): bool
    {
        if ($warData['att_alliance_id'] == env("PW_ALLIANCE_ID") || $warData['def_alliance_id'] == env(
                "PW_ALLIANCE_ID"
            )) {
            return true;
        }

        return false;
    }
}
