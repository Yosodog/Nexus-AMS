<?php

namespace App\Services\Admin\MemberTimeline;

use App\DataTransferObjects\Admin\MemberTimelineItem;
use App\DataTransferObjects\Admin\MemberTimelineResult;
use App\Enums\MemberTimelineCategory;
use App\Models\Nation;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class MemberTimelineService
{
    public const DISPLAY_LIMIT = 30;

    private const SOURCE_RECORD_LIMIT = self::DISPLAY_LIMIT + 1;

    /** @var list<MemberTimelineSource> */
    private array $sources;

    public function __construct(
        MembershipTimelineSource $membership,
        ApplicationTimelineSource $applications,
        FinanceTimelineSource $finance,
        AuditTimelineSource $audits,
        MilitaryTimelineSource $military,
        CommunicationTimelineSource $communications,
    ) {
        $this->sources = [
            $membership,
            $applications,
            $finance,
            $audits,
            $military,
            $communications,
        ];
    }

    /** @param list<MemberTimelineCategory>|null $requestedCategories */
    public function forNation(
        Nation $nation,
        User $viewer,
        ?array $requestedCategories = null,
    ): MemberTimelineResult {
        $visibleSources = collect($this->sources)
            ->filter(fn (MemberTimelineSource $source): bool => $source->visibleTo($viewer));
        $availableCategories = $visibleSources
            ->map(fn (MemberTimelineSource $source): MemberTimelineCategory => $source->category())
            ->uniqueStrict(fn (MemberTimelineCategory $category): string => $category->value)
            ->values();
        $selectedCategories = $requestedCategories === null
            ? $availableCategories
            : $availableCategories
                ->filter(fn (MemberTimelineCategory $category): bool => in_array($category, $requestedCategories, true))
                ->values();
        $items = collect();
        $unavailableCategories = collect();

        $visibleSources
            ->filter(fn (MemberTimelineSource $source): bool => $selectedCategories->containsStrict($source->category()))
            ->each(function (MemberTimelineSource $source) use ($nation, $viewer, $items, $unavailableCategories): void {
                try {
                    $items->push(...$source->items($nation, $viewer, self::SOURCE_RECORD_LIMIT));
                } catch (QueryException $exception) {
                    $unavailableCategories->push($source->category());

                    Log::warning('Member timeline source is temporarily unavailable.', [
                        'source' => $source::class,
                        'category' => $source->category()->value,
                        'error_code' => (string) $exception->getCode(),
                    ]);
                }
            });

        $ordered = $this->chronological($this->deduplicate($items));

        return new MemberTimelineResult(
            items: $ordered->take(self::DISPLAY_LIMIT)->values(),
            availableCategories: $availableCategories->all(),
            selectedCategories: $selectedCategories->all(),
            unavailableCategories: $unavailableCategories
                ->uniqueStrict(fn (MemberTimelineCategory $category): string => $category->value)
                ->values()
                ->all(),
            isTruncated: $ordered->count() > self::DISPLAY_LIMIT,
            displayLimit: self::DISPLAY_LIMIT,
        );
    }

    /**
     * Prefer an authoritative retained event over a synthetic current-state projection.
     *
     * @param  Collection<int, MemberTimelineItem>  $items
     * @return Collection<int, MemberTimelineItem>
     */
    private function deduplicate(Collection $items): Collection
    {
        return $items
            ->sort(function (MemberTimelineItem $left, MemberTimelineItem $right): int {
                return ($right->sourcePriority <=> $left->sourcePriority)
                    ?: ($right->occurredAt->getTimestamp() <=> $left->occurredAt->getTimestamp())
                    ?: strcmp($left->sourceKey, $right->sourceKey);
            })
            ->uniqueStrict(fn (MemberTimelineItem $item): string => $item->deduplicationKey)
            ->values();
    }

    /**
     * @param  Collection<int, MemberTimelineItem>  $items
     * @return Collection<int, MemberTimelineItem>
     */
    private function chronological(Collection $items): Collection
    {
        return $items
            ->sort(function (MemberTimelineItem $left, MemberTimelineItem $right): int {
                return ($right->occurredAt->getTimestamp() <=> $left->occurredAt->getTimestamp())
                    ?: strcmp($left->sourceKey, $right->sourceKey);
            })
            ->values();
    }
}
