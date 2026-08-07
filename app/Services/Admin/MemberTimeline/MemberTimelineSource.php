<?php

namespace App\Services\Admin\MemberTimeline;

use App\DataTransferObjects\Admin\MemberTimelineItem;
use App\Enums\MemberTimelineCategory;
use App\Models\Nation;
use App\Models\User;
use Illuminate\Support\Collection;

interface MemberTimelineSource
{
    public function category(): MemberTimelineCategory;

    public function visibleTo(User $viewer): bool;

    /** @return Collection<int, MemberTimelineItem> */
    public function items(Nation $nation, User $viewer, int $recordLimit): Collection;
}
