<?php

namespace App\Jobs;

use App\Models\CityGrant;
use App\Models\Nation;
use App\Services\AllianceMembershipService;
use App\Services\CityGrantService;
use App\Services\PWMessageService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class SendCityGrantRemindersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 900;

    /**
     * @param  array<int, int>  $grantIds
     */
    public function __construct(
        public array $grantIds,
        public string $adminMessage,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(PWMessageService $messageService, AllianceMembershipService $membershipService): void
    {
        $grants = CityGrant::query()
            ->whereIn('id', $this->grantIds)
            ->where('enabled', true)
            ->get();

        if ($grants->isEmpty()) {
            Log::warning('City grant reminder job skipped because no eligible grants were selected.', [
                'grant_ids' => $this->grantIds,
            ]);

            return;
        }

        $grantsByCityNumber = $grants->keyBy('city_number');
        $applyUrl = route('grants.city');
        $messageTemplate = trim($this->adminMessage);
        $allianceIds = $membershipService->getAllianceIds();

        $nations = Nation::query()
            ->whereIn('alliance_id', $allianceIds)
            ->get();

        foreach ($nations as $nation) {
            $nextCityNumber = (int) $nation->num_cities + 1;
            $grant = $grantsByCityNumber->get($nextCityNumber);

            if (! $grant) {
                continue;
            }

            try {
                CityGrantService::validateEligibility($grant, $nation);
            } catch (ValidationException) {
                continue;
            }

            $leaderName = $nation->leader_name ?: 'there';
            $body = $this->buildMessage($leaderName, $messageTemplate, $applyUrl);

            $messageService->sendMessage((int) $nation->id, 'City Grant Reminder', $body);
        }
    }

    private function buildMessage(string $leaderName, string $message, string $applyUrl): string
    {
        return "Hi {$leaderName},\n\n{$message}\n\nPlease click [link={$applyUrl}]here[/here] to apply for a city grant";
    }
}
