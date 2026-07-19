<?php

namespace App\Jobs;

use App\Models\Alliance;
use App\Services\AllianceQueryService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class UpdateAllianceJob implements ShouldQueue
{
    use Queueable;

    public $timeout = 20;

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
                try {
                    $allianceModel = Alliance::getById($allianceData['id']);
                } catch (ModelNotFoundException) {
                    $alliance = AllianceQueryService::getAllianceById($allianceData['id']);
                    Alliance::updateFromAPI($alliance);

                    continue;
                }

                foreach ($allianceData as $key => $data) {
                    if (! in_array($key, $this->skips)) {
                        $allianceModel->$key = $data ?? '';
                    }
                }

                $allianceModel->save();
            }
        } catch (Throwable $e) {
            Log::error('Failed to update alliances from subscription.', [
                'alliance_ids' => collect($this->alliancesData)->pluck('id')->filter()->take(10)->values()->all(),
                'record_count' => count($this->alliancesData),
                'exception_class' => $e::class,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
