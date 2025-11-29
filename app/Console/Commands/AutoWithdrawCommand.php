<?php

namespace App\Console\Commands;

use App\Models\Nation;
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
            ->get();

        foreach ($nations as $nation) {
            try {
                $this->autoWithdrawService->evaluateAndExecute($nation);
            } catch (\Throwable $exception) {
                $this->warn("Skipping nation {$nation->id}: {$exception->getMessage()}");
            }
        }

        return self::SUCCESS;
    }
}
