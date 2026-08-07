<?php

namespace App\Services\StaffWorkQueue;

use App\Models\StaffWorkQueueSavedView;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class StaffWorkQueueSavedViewStore
{
    private const MAX_SAVED_VIEWS = 10;

    /**
     * @return list<array{id: string, name: string, filters: array<string, string>, created_at: string}>
     */
    public function all(User $user): array
    {
        return StaffWorkQueueSavedView::query()
            ->whereBelongsTo($user)
            ->latest('updated_at')
            ->latest('id')
            ->get()
            ->map(fn (StaffWorkQueueSavedView $view): array => $this->toArray($view))
            ->all();
    }

    /**
     * @return array{id: string, name: string, filters: array<string, string>, created_at: string}|null
     */
    public function find(User $user, string $id): ?array
    {
        $view = StaffWorkQueueSavedView::query()
            ->whereBelongsTo($user)
            ->where('public_id', $id)
            ->first();

        return $view ? $this->toArray($view) : null;
    }

    public function save(User $user, string $name, StaffWorkQueueFilterSet $filters): string
    {
        return DB::transaction(function () use ($user, $name, $filters): string {
            $view = StaffWorkQueueSavedView::query()->create([
                'user_id' => $user->getKey(),
                'public_id' => (string) Str::uuid(),
                'name' => trim($name),
                'filters' => $filters->toArray(),
            ]);

            $staleIds = StaffWorkQueueSavedView::query()
                ->whereBelongsTo($user)
                ->latest('updated_at')
                ->latest('id')
                ->pluck('id')
                ->slice(self::MAX_SAVED_VIEWS);

            if ($staleIds->isNotEmpty()) {
                StaffWorkQueueSavedView::query()
                    ->whereBelongsTo($user)
                    ->whereIn('id', $staleIds)
                    ->delete();
            }

            return $view->public_id;
        });
    }

    public function delete(User $user, string $id): bool
    {
        return StaffWorkQueueSavedView::query()
            ->whereBelongsTo($user)
            ->where('public_id', $id)
            ->delete() === 1;
    }

    /**
     * @return array{id: string, name: string, filters: array<string, string>, created_at: string}
     */
    private function toArray(StaffWorkQueueSavedView $view): array
    {
        return [
            'id' => $view->public_id,
            'name' => $view->name,
            'filters' => is_array($view->filters) ? $view->filters : [],
            'created_at' => $view->created_at->toIso8601String(),
        ];
    }
}
