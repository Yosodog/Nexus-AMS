<?php

namespace App\Console\Commands;

use App\Jobs\RefreshRaidIntelligence;
use App\Models\Nation;
use App\Services\RaidIntelligenceDemand;
use App\Services\RuntimeCapabilities;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class RefreshRaidTargets extends Command
{
    protected $signature = 'raids:refresh-intelligence
        {--limit=100 : Maximum nations queued per run}
        {--priority-only : Refresh only explicitly requested nations}
        {--background : Advance only the global nation cursor}';

    protected $description = 'Refresh public raid intelligence in bounded batches.';

    public function handle(RuntimeCapabilities $capabilities, RaidIntelligenceDemand $demand): int
    {
        if (! $capabilities->writesPublicWorld()) {
            $this->components->info('Public raid intelligence is maintained by the world writer.');

            return self::SUCCESS;
        }
        $limit = max(1, min(500, (int) $this->option('limit')));
        $priorityOnly = (bool) $this->option('priority-only');
        $background = (bool) $this->option('background');
        if ($priorityOnly && $background) {
            $this->components->error('Choose either --priority-only or --background.');

            return self::FAILURE;
        }

        if ($background) {
            $nationIds = $this->backgroundNationIds($limit);
        } else {
            $nationIds = $demand->take($limit);
        }
        if ($nationIds !== []) {
            RefreshRaidIntelligence::dispatch(array_map('intval', $nationIds));
        }
        $this->components->info('Queued '.count($nationIds).' nations.');

        return self::SUCCESS;
    }

    /** @return list<int> */
    private function backgroundNationIds(int $limit): array
    {
        if ($limit <= 0) {
            return [];
        }

        $cursor = (int) Cache::get('raid-intelligence:cursor', 0);
        $ids = Nation::query()->where('id', '>', $cursor)->orderBy('id')->limit($limit)->pluck('id')->all();
        Cache::put('raid-intelligence:cursor', $ids === [] ? 0 : max($ids), 86400);

        return array_map('intval', $ids);
    }
}
