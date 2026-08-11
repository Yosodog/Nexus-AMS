<?php

namespace App\Console\Commands;

use App\Models\DepositImportCheckpoint;
use App\Services\AllianceMembershipService;
use App\Services\DepositService;
use App\Services\QueryService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessDeposits extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'accounts:process-deposits';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Processes deposits from in-game accounts';

    public function __construct(private readonly AllianceMembershipService $membershipService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $allianceIds = $this->membershipService->getAllianceIds();

        if ($allianceIds->isEmpty()) {
            $this->error('No alliance IDs are configured; skipping deposit processing.');

            return Command::FAILURE;
        }

        if (DepositService::getPendingDeposits()->isEmpty()) {
            return Command::SUCCESS;
        }

        $hadFailures = false;

        foreach ($allianceIds as $allianceId) {
            $credentials = $this->membershipService->getCredentialsForAlliance($allianceId);

            if ($credentials === null) {
                $message = 'Alliance API credentials are not configured.';
                DepositImportCheckpoint::recordFailure($allianceId, $message);
                Log::warning('Skipped deposit import because alliance credentials are unavailable.', [
                    'alliance_id' => $allianceId,
                ]);
                $this->warn("Skipped alliance {$allianceId}: {$message}");
                $hadFailures = true;

                continue;
            }

            try {
                $lastScannedId = DepositService::processDeposits(
                    $allianceId,
                    $this->resolveQueryClient($credentials),
                );

                $this->info("Processed deposits for alliance {$allianceId}. Last scanned ID: {$lastScannedId}");
            } catch (Throwable $exception) {
                Log::error('Failed to process alliance deposits.', [
                    'alliance_id' => $allianceId,
                    'message' => $exception->getMessage(),
                ]);
                $this->error("Failed to process deposits for alliance {$allianceId}: {$exception->getMessage()}");
                $hadFailures = true;
            }
        }

        return $hadFailures ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * @param  array{api_key: string, mutation_key: string|null}  $credentials
     */
    protected function resolveQueryClient(array $credentials): QueryService
    {
        return app(QueryService::class, [
            'apiKey' => $credentials['api_key'],
        ]);
    }
}
