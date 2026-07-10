<?php

namespace App\Console\Commands;

use App\Exceptions\UserErrorException;
use App\Models\Nation;
use App\Services\AccountService;
use App\Services\AutoWithdrawService;
use App\Services\SettingService;
use Illuminate\Console\Command;

class AutoWithdrawCommand extends Command
{
    protected $signature = 'auto:withdraw';

    protected $description = 'Evaluate auto withdraw settings and dispatch withdrawals for eligible nations';

    public function __construct(private AutoWithdrawService $autoWithdrawService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! SettingService::isAutoWithdrawEnabled()) {
            $this->info('Auto withdraw is disabled by admins.');

            return self::SUCCESS;
        }

        $nations = Nation::query()
            ->whereHas('autoWithdrawSettings', fn ($query) => $query->where('enabled', true))
            ->with([
                'resources',
                'autoWithdrawSettings' => fn ($query) => $query
                    ->where('enabled', true)
                    ->with('account'),
            ])
            ->get();

        try {
            $blockadedNationIds = AccountService::getBlockadedNationIds($nations->pluck('id')->all());
        } catch (UserErrorException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        foreach ($nations as $nation) {
            if (in_array($nation->id, $blockadedNationIds, true)) {
                $this->info("Skipping blockaded nation {$nation->id}.");

                continue;
            }

            try {
                $this->autoWithdrawService->evaluateAndExecute(
                    $nation,
                    featureStatusVerified: true,
                    blockadeStatusVerified: true
                );
            } catch (\Throwable $exception) {
                $this->warn("Skipping nation {$nation->id}: {$exception->getMessage()}");
            }
        }

        return self::SUCCESS;
    }
}
