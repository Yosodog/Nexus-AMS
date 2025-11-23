<?php

namespace App\Console\Commands;

use App\Models\AuditRule;
use App\Services\Audit\AuditService;
use Illuminate\Console\Command;

class RunAuditsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'audits:run';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Evaluate enabled audit rules against member nations and cities.';

    public function __construct(private readonly AuditService $auditService)
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $ruleCount = AuditRule::query()->enabled()->count();

        if ($ruleCount === 0) {
            $this->info('No enabled audit rules to run.');

            return self::SUCCESS;
        }

        $this->info("Running {$ruleCount} audit rules...");
        $this->auditService->runAllEnabledRules();
        $this->info('Audit run complete.');

        return self::SUCCESS;
    }
}
