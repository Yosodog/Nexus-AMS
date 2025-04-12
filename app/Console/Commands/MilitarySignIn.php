<?php

namespace App\Console\Commands;

use App\Exceptions\PWQueryFailedException;
use App\Models\Nation;
use App\Services\AllianceQueryService;
use App\Services\SignInService;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
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

    /**
     * @param SignInService $signInService
     */
    public function __construct(protected SignInService $signInService)
    {
        parent::__construct();
    }

    /**
     * @return int
     * @throws PWQueryFailedException
     * @throws ConnectionException
     */
    public function handle(): int
    {
        $allianceId = env("PW_ALLIANCE_ID");

        $alliance = AllianceQueryService::getAllianceWithMembersById($allianceId);

        $this->info("Found {$alliance->nations->count()} nations in alliance ID $allianceId");

        foreach ($alliance->nations as $nation) {
            try {
                if ($nation->isApplicant()) {
                    continue;
                }

                if ($nation->vacation_mode_turns > 0) {
                    continue;
                }

                // TODO Handle if the nation isn't sharing their resources by spamming them with messages.

                $this->signInService->snapshotNation($nation);
                Nation::updateFromAPI($nation); // Why not update it while we're here
                $this->line("✅ {$nation->nation_name}");
            } catch (Throwable $e) {
                $this->error("❌ {$nation->nation_name}: " . $e->getMessage());
            }
        }

        return Command::SUCCESS;
    }
}
