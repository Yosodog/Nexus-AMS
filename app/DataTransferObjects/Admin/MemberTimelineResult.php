<?php

namespace App\DataTransferObjects\Admin;

use App\Enums\MemberTimelineCategory;
use Illuminate\Support\Collection;

final readonly class MemberTimelineResult
{
    /**
     * @param  Collection<int, MemberTimelineItem>  $items
     * @param  list<MemberTimelineCategory>  $availableCategories
     * @param  list<MemberTimelineCategory>  $selectedCategories
     * @param  list<MemberTimelineCategory>  $unavailableCategories
     */
    public function __construct(
        public Collection $items,
        public array $availableCategories,
        public array $selectedCategories,
        public array $unavailableCategories,
        public bool $isTruncated,
        public int $displayLimit,
    ) {}

    public function isSelected(MemberTimelineCategory $category): bool
    {
        return in_array($category, $this->selectedCategories, true);
    }

    public function hasUnavailableSources(): bool
    {
        return $this->unavailableCategories !== [];
    }
}
