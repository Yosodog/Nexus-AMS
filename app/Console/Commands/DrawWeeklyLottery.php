<?php

namespace App\Console\Commands;

use App\Services\LotteryService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('lottery:draw')]
#[Description('Draw all weekly lotteries whose sales windows have closed')]
class DrawWeeklyLottery extends Command
{
    public function __construct(private readonly LotteryService $lotteryService)
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $drawings = $this->lotteryService->drawExpiredDrawings();

        $this->components->info(sprintf(
            'Completed %d expired lottery %s.',
            $drawings->count(),
            str('drawing')->plural($drawings->count()),
        ));

        return self::SUCCESS;
    }
}
