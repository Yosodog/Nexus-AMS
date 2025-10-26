<?php

namespace App\Console\Commands;

use App\Services\AllianceMembershipService;
use App\Services\QueryService;
use App\Services\TaxService;
use Illuminate\Console\Command;
use Throwable;

class CollectTaxes extends Command
{
    /**
     * @var string
     */
    protected $signature = 'taxes:collect';
    /**
     * @var string
     */
    protected $description = 'Collects taxes for the alliance';

    public function __construct(protected AllianceMembershipService $membershipService)
    {
        parent::__construct();
    }

    /**
     * @return int
     */
    public function handle(): int
    {
        $allianceIds = $this->membershipService->getAllianceIds();

        if ($allianceIds->isEmpty()) {
            $this->error('No alliance IDs configured for tax collection.');

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
                $lastScanned = TaxService::updateAllianceTaxes(
                    $allianceId,
                    $this->resolveQueryClient($credentials)
                );
            } catch (Throwable $exception) {
                $this->error("Failed to update taxes for alliance {$allianceId}: {$exception->getMessage()}");

                continue;
            }

            $this->info("Updated alliance {$allianceId} taxes. Last scanned ID: {$lastScanned}");
        }

        return Command::SUCCESS;
    }

    /**
     * Build a query client configured for the alliance we need to poll.
     *
     * We reuse the offshore API key when available so protected monetary and
     * resource fields remain visible during tax imports.
     *
     * @param array{api_key: string|null, mutation_key: string|null}|null $credentials
     * @return QueryService
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
