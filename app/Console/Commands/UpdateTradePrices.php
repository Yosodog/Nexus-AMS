<?php

namespace App\Console\Commands;

use App\Models\TradePrice;
use App\Services\ApiDateNormalizer;
use App\Services\TradePriceService;
use Illuminate\Console\Command;
use Throwable;

class UpdateTradePrices extends Command
{
    protected $signature = 'trades:update';

    protected $description = 'Fetch and save the current trade prices from PW API';

    public function __construct(protected TradePriceService $tradePriceService)
    {
        parent::__construct();
    }

    public function handle(): void
    {
        $this->info('Fetching latest trade prices...');

        try {
            $graphqlPrice = $this->tradePriceService->pullFromGraphQL();
            $normalizedDate = ApiDateNormalizer::normalizeDate($graphqlPrice->date);

            if ($normalizedDate === null) {
                throw new \UnexpectedValueException('Trade price API returned an invalid date.');
            }

            TradePrice::create([
                'date' => $normalizedDate,
                'coal' => (int) $graphqlPrice->coal,
                'oil' => (int) $graphqlPrice->oil,
                'uranium' => (int) $graphqlPrice->uranium,
                'iron' => (int) $graphqlPrice->iron,
                'bauxite' => (int) $graphqlPrice->bauxite,
                'lead' => (int) $graphqlPrice->lead,
                'gasoline' => (int) $graphqlPrice->gasoline,
                'munitions' => (int) $graphqlPrice->munitions,
                'steel' => (int) $graphqlPrice->steel,
                'aluminum' => (int) $graphqlPrice->aluminum,
                'food' => (int) $graphqlPrice->food,
                'credits' => (int) $graphqlPrice->credits,
            ]);

            $this->info('Trade prices saved successfully.');
        } catch (Throwable $e) {
            $this->error("Failed to update trade prices: {$e->getMessage()}");
        }
    }
}
