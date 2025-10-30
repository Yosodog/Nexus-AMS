<?php

namespace App\Console\Commands;

use App\Services\RecruitmentService;
use Illuminate\Console\Command;

class RecruitNations extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'recruit:nations';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send recruitment messages to the newest nations.';

    /**
     * Execute the console command.
     */
    public function handle(RecruitmentService $recruitmentService): int
    {
        $recruitmentService->runRecruitmentCycle();

        $this->info('Recruitment cycle completed.');

        return self::SUCCESS;
    }
}
