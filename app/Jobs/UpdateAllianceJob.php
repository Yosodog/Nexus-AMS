<?php

namespace App\Jobs;

use App\Models\Alliance;
use App\Services\AllianceQueryService;
use App\Services\SubscriptionRecordQuarantine;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;
use TypeError;
use ValueError;

class UpdateAllianceJob implements ShouldQueue
{
    use Queueable;

    public $timeout = 20;

    public array $alliancesData;

    /** @var list<string> */
    private const UPDATABLE_FIELDS = [
        'name',
        'acronym',
        'score',
        'color',
        'average_score',
        'accept_members',
        'flag',
        'forum_link',
        'discord_link',
        'wiki_link',
        'rank',
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
        $quarantine = app(SubscriptionRecordQuarantine::class);

        foreach ($this->alliancesData as $allianceData) {
            try {
                $this->updateAlliance($allianceData);
            } catch (InvalidArgumentException|TypeError|ValueError $exception) {
                $quarantine->quarantine(
                    'alliance',
                    'update',
                    $allianceData,
                    'invalid_alliance_update: '.$exception->getMessage()
                );
            } catch (Throwable $exception) {
                Log::error('Failed to update alliance from subscription.', [
                    'alliance_id' => is_array($allianceData) ? ($allianceData['id'] ?? null) : null,
                    'exception_class' => $exception::class,
                    'error' => $exception->getMessage(),
                ]);

                throw $exception;
            }
        }
    }

    private function updateAlliance(mixed $allianceData): void
    {
        if (! is_array($allianceData)
            || ! isset($allianceData['id'])
            || (! is_int($allianceData['id']) && ! is_string($allianceData['id']))
            || filter_var($allianceData['id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            throw new InvalidArgumentException('A positive alliance ID is required.');
        }

        $allianceId = (int) $allianceData['id'];

        try {
            $allianceModel = Alliance::getById($allianceId);
        } catch (ModelNotFoundException) {
            $alliance = AllianceQueryService::getAllianceById($allianceId);
            Alliance::updateFromAPI($alliance);

            return;
        }

        foreach (self::UPDATABLE_FIELDS as $field) {
            if (array_key_exists($field, $allianceData)) {
                $allianceModel->{$field} = $allianceData[$field];
            }
        }

        $allianceModel->save();
    }
}
