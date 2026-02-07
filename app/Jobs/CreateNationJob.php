<?php

namespace App\Jobs;

use App\Models\Nation;
use App\Services\NationQueryService;
use Exception;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class CreateNationJob implements ShouldQueue
{
    use Queueable;

    public $timeout = 5;

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
     */
    public function handle(): void
    {
        foreach ($this->nationsData as $nationData) {
            $nationId = $nationData['id'] ?? null;

            if (! $nationId) {
                continue;
            }

            try {
                Cache::lock($this->lockKey($nationId), 30)->block(5, function () use ($nationId): void {
                    $nationModel = NationQueryService::getNationById($nationId);

                    // Use updateFromAPI() to create the nation
                    Nation::updateFromAPI($nationModel);
                });
            } catch (LockTimeoutException $e) {
                $this->release(10);

                return;
            } catch (Exception $e) {
                Log::error('Failed to create nations', ['error' => $e->getMessage()]);
            }
        }
    }

    private function lockKey(int $nationId): string
    {
        return 'nation:create:'.$nationId;
    }
}
