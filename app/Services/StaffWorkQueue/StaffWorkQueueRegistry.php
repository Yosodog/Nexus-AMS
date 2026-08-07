<?php

namespace App\Services\StaffWorkQueue;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

final class StaffWorkQueueRegistry
{
    private const CACHE_SCHEMA_VERSION = 1;

    /** @var array<string, StaffWorkQueueSource> */
    private array $sources;

    /**
     * @param  iterable<StaffWorkQueueSource>  $sources
     */
    public function __construct(iterable $sources)
    {
        $this->sources = [];

        foreach ($sources as $source) {
            if (isset($this->sources[$source->type()])) {
                throw new InvalidArgumentException("Duplicate staff work queue source [{$source->type()}].");
            }

            $this->sources[$source->type()] = $source;
        }
    }

    /**
     * @return array{
     *     items: list<array<string, mixed>>,
     *     counts: array<string, int>,
     *     failures: array<string, array{label: string}>,
     *     generated_at: string,
     *     complete: bool
     * }
     */
    public function snapshot(bool $forceRefresh = false): array
    {
        if ($forceRefresh) {
            $this->flushCache();
        }

        $cached = Cache::get($this->cacheKey());

        if ($this->isValidSnapshot($cached)) {
            return $this->publicSnapshot($cached);
        }

        $snapshot = $this->buildSnapshot();
        $ttl = $snapshot['failures'] === [] ? $this->cacheTtl() : $this->failureCacheTtl();
        Cache::put($this->cacheKey(), $snapshot, now()->addSeconds($ttl));

        return $this->publicSnapshot($snapshot);
    }

    /**
     * @return array{
     *     items: list<array<string, mixed>>,
     *     counts: array<string, int>,
     *     failures: array<string, array{label: string}>,
     *     generated_at: string,
     *     complete: bool,
     *     types: array<string, string>,
     *     total: int
     * }
     */
    public function forUser(User $user, bool $forceRefresh = false): array
    {
        $allowedTypes = $this->allowedTypes($user);

        if ($allowedTypes === []) {
            return [
                'items' => [],
                'counts' => [],
                'failures' => [],
                'generated_at' => now()->toIso8601String(),
                'complete' => true,
                'types' => [],
                'total' => 0,
            ];
        }

        $snapshot = $this->snapshot($forceRefresh);
        $allowedTypeKeys = array_keys($allowedTypes);

        $items = array_values(array_filter(
            $snapshot['items'],
            static fn (array $item): bool => in_array($item['type'] ?? null, $allowedTypeKeys, true),
        ));
        $counts = collect($snapshot['counts'])->only($allowedTypeKeys)->map(fn (mixed $count): int => (int) $count)->all();
        $failures = collect($snapshot['failures'])->only($allowedTypeKeys)->all();

        return [
            'items' => $items,
            'counts' => $counts,
            'failures' => $failures,
            'generated_at' => $snapshot['generated_at'],
            'complete' => $failures === [],
            'types' => $allowedTypes,
            'total' => array_sum($counts),
        ];
    }

    public function canView(User $user): bool
    {
        return $this->allowedTypes($user) !== [];
    }

    public function flushCache(): void
    {
        Cache::forget($this->cacheKey());
    }

    /**
     * @return array<string, string>
     */
    public function allowedTypes(User $user): array
    {
        $gate = Gate::forUser($user);

        return collect($this->sources)
            ->filter(fn (StaffWorkQueueSource $source): bool => $gate->allows($source->ability()))
            ->mapWithKeys(fn (StaffWorkQueueSource $source): array => [$source->type() => $source->label()])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function buildSnapshot(): array
    {
        $items = [];
        $counts = [];
        $failures = [];

        foreach ($this->sources as $type => $source) {
            try {
                $sourceItems = $source->load();

                foreach ($sourceItems as $item) {
                    if ($item->type !== $type) {
                        throw new InvalidArgumentException("Work queue source [{$type}] returned an item for [{$item->type}].");
                    }

                    $items[] = $item->toArray();
                }

                $counts[$type] = count($sourceItems);
            } catch (Throwable $exception) {
                $failures[$type] = ['label' => $source->label()];

                Log::error('Staff work queue source failed.', [
                    'source' => $type,
                    'source_class' => $source::class,
                    'exception' => $exception::class,
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        return [
            'schema' => self::CACHE_SCHEMA_VERSION,
            'items' => $items,
            'counts' => $counts,
            'failures' => $failures,
            'generated_at' => now()->toIso8601String(),
        ];
    }

    private function isValidSnapshot(mixed $snapshot): bool
    {
        return is_array($snapshot)
            && ($snapshot['schema'] ?? null) === self::CACHE_SCHEMA_VERSION
            && is_array($snapshot['items'] ?? null)
            && is_array($snapshot['counts'] ?? null)
            && is_array($snapshot['failures'] ?? null)
            && is_string($snapshot['generated_at'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array{
     *     items: list<array<string, mixed>>,
     *     counts: array<string, int>,
     *     failures: array<string, array{label: string}>,
     *     generated_at: string,
     *     complete: bool
     * }
     */
    private function publicSnapshot(array $snapshot): array
    {
        return [
            'items' => array_values($snapshot['items']),
            'counts' => $snapshot['counts'],
            'failures' => $snapshot['failures'],
            'generated_at' => $snapshot['generated_at'],
            'complete' => $snapshot['failures'] === [],
        ];
    }

    private function cacheKey(): string
    {
        return (string) config('pending_requests.projection_cache_key', 'pending_requests.work_queue.v1');
    }

    private function cacheTtl(): int
    {
        $configured = (int) config('pending_requests.cache_ttl_seconds', 900);

        return min(max($configured, 600), 1800);
    }

    private function failureCacheTtl(): int
    {
        $configured = (int) config('pending_requests.failure_cache_ttl_seconds', 60);

        return min(max($configured, 15), 120);
    }
}
