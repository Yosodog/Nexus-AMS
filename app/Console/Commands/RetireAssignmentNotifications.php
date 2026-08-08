<?php

namespace App\Console\Commands;

use App\Models\DiscordNotificationPreference;
use App\Services\Discord\PrivateNotificationService;
use Illuminate\Console\Command;

class RetireAssignmentNotifications extends Command
{
    protected $signature = 'alerts:retire-assignment-notifications {--dry-run : Report pending rows and preferences without changing them}';

    protected $description = 'Retire proactive war and spy assignment Discord notifications';

    public function handle(PrivateNotificationService $notifications): int
    {
        $pendingCount = $notifications->pendingAssignmentNotificationCount();
        $activeLeaseCount = $notifications->activeAssignmentNotificationLeaseCount();
        $preferenceCount = DiscordNotificationPreference::query()
            ->whereIn('category', ['war_assignments', 'spy_assignments'])
            ->count();

        if ($this->option('dry-run')) {
            $this->components->info("Would suppress {$pendingCount} pending assignment notification(s).");
            $this->components->info("Active assignment notification leases: {$activeLeaseCount}.");
            $this->components->info("Would remove {$preferenceCount} assignment preference row(s).");

            return self::SUCCESS;
        }

        if ($activeLeaseCount > 0) {
            $this->components->error(
                'Pause the Discord worker and wait for active assignment notification leases to finish before retiring the feature.',
            );

            return self::FAILURE;
        }

        $suppressedCount = $notifications->suppressPendingAssignmentNotifications();
        $removedPreferenceCount = DiscordNotificationPreference::query()
            ->whereIn('category', ['war_assignments', 'spy_assignments'])
            ->delete();

        $this->components->info(
            "Suppressed {$suppressedCount} pending assignment notification(s) and removed {$removedPreferenceCount} preference row(s).",
        );

        return self::SUCCESS;
    }
}
