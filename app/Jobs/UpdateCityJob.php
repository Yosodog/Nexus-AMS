<?php

namespace App\Jobs;

use App\GraphQL\Models\City as CityGraphQL;
use App\Models\City;
use App\Models\Nation;
use App\Services\CityQueryService;
use App\Services\NationQueryService;
use Exception;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class UpdateCityJob implements ShouldQueue
{
    use Queueable;

    public $timeout = 5;

    public array $citiesData;

    public array $skips = ['nuke_date'];

    /**
     * Create a new job instance.
     */
    public function __construct(array $citiesData)
    {
        $this->citiesData = $citiesData;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        foreach ($this->citiesData as $cityData) {
            $cityFromApi = null;
            $nationId = $cityData['nation_id'] ?? null;

            if (! $nationId) {
                $cityFromApi = CityQueryService::getCityById($cityData['id']);
                $nationId = $cityFromApi->nation_id ?? null;
            }

            try {
                Cache::lock($this->lockKey($nationId ?? $cityData['id']), 30)->block(5, function () use (
                    $cityData,
                    $nationId,
                    $cityFromApi
                ): void {
                    $this->ensureNationExists($nationId);
                    $this->upsertCity($cityData, $cityFromApi);
                });
            } catch (LockTimeoutException $e) {
                $this->release(10);

                return;
            } catch (Exception $e) {
                Log::error('Failed to update cities', ['error' => $e->getMessage()]);
            }
        }
    }

    private function lockKey(int $nationId): string
    {
        return 'nation:create:'.$nationId;
    }

    private function ensureNationExists(?int $nationId): void
    {
        if (! $nationId) {
            return;
        }

        if (Nation::query()->whereKey($nationId)->exists()) {
            return;
        }

        $nationModel = NationQueryService::getNationById($nationId);
        Nation::updateFromAPI($nationModel);
    }

    private function upsertCity(array $cityData, ?CityGraphQL $cityFromApi): void
    {
        try {
            $cityModel = City::getById($cityData['id']);

            foreach ($cityData as $key => $data) {
                if (in_array($key, $this->skips)) {
                    continue;
                }

                $cityModel->$key = $data ?? '';
            }

            $cityModel->save();
        } catch (ModelNotFoundException $e) {
            $city = $cityFromApi ?? CityQueryService::getCityById($cityData['id']);

            try {
                City::updateFromAPI($city);
            } catch (UniqueConstraintViolationException $e) {
                $trashedCity = City::withTrashed()
                    ->find($city->id);

                if ($trashedCity && $trashedCity->trashed()) {
                    $trashedCity->restore();
                    City::updateFromAPI($city);
                } else {
                    throw $e;
                }
            }
        }
    }
}
