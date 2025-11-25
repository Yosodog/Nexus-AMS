<?php

namespace App\Console\Commands;

use App\Models\Nation;
use App\Services\AllianceMembershipService;
use App\Services\AllianceQueryService;
use App\Services\QueryService;
use App\Services\SignInService;
use Illuminate\Console\Command;
use Throwable;

class MilitarySignIn extends Command
{
    /**
     * @var string
     */
    protected $signature = 'military:sign-in';

    /**
     * @var string
     */
    protected $description = 'Run daily sign-in for all alliance nations and snapshot their data';

    public function __construct(
        protected SignInService $signInService,
        protected AllianceMembershipService $membershipService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $allianceIds = $this->membershipService->getAllianceIds();

        if ($allianceIds->isEmpty()) {
            $this->error('No alliance IDs configured for sign-in.');

            return Command::FAILURE;
        }

        $primaryAllianceId = $this->membershipService->getPrimaryAllianceId();

        foreach ($allianceIds as $allianceId) {
            $credentials = $this->membershipService->getCredentialsForAlliance($allianceId);

            if ($credentials === null) {
                if ($allianceId === $primaryAllianceId) {
                    $this->error('Primary alliance API credentials are not configured.');

                    return Command::FAILURE;
                }

                $this->warn(
                    "Alliance {$allianceId} is missing an API key; falling back to the primary key. Protected values may be blank."
                );
            }

            try {
                $alliance = AllianceQueryService::getAllianceWithMembersById(
                    $allianceId,
                    $this->resolveQueryClient($credentials)
                );
            } catch (Throwable $exception) {
                $this->error("Failed to load alliance {$allianceId}: {$exception->getMessage()}");

                continue;
            }

            if (! isset($alliance->nations)) {
                $this->warn("Alliance {$allianceId} returned no nations from the API.");

                continue;
            }

            $this->info("Found {$alliance->nations->count()} nations in alliance ID {$allianceId}");

            foreach ($alliance->nations as $nation) {
                try {
                    if ($nation->isApplicant()) {
                        continue;
                    }

                    if ($nation->vacation_mode_turns > 0) {
                        continue;
                    }

                    // TODO Handle if the nation isn't sharing their resources by spamming them with messages.

                    $mmrScore = $this->signInService->snapshotNation($nation);
                    Nation::updateFromAPI($nation); // Why not update it while we're here
                    $this->line("✅ {$nation->nation_name} (MMR {$mmrScore}%)");
                } catch (Throwable $e) {
                    $this->error("❌ {$nation->nation_name}: ".$e->getMessage());
                }
            }
        }

        return Command::SUCCESS;
    }

    /**
     * Build a query client configured for the alliance we are about to poll.
     *
     * The goal here is to ensure we use the offshore's API key whenever we
     * reach outside the primary alliance so protected resource fields remain
     * available to the snapshot service.
     *
     * @param  array{api_key: string|null, mutation_key: string|null}|null  $credentials
     */
    protected function resolveQueryClient(?array $credentials): QueryService
    {
        $parameters = [];

        if (! empty($credentials['api_key'])) {
            $parameters['apiKey'] = $credentials['api_key'];
        }

        return app(QueryService::class, $parameters);
    }
}
