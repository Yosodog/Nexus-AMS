<?php

namespace App\Services\Discord;

use App\Enums\DiscordQueueLane;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Lets idle Discord worker polls skip the `discord_queue` scan.
 *
 * After a database check finds nothing claimable for a connection and lane, the lease service
 * stores an "empty lane" marker holding the claimable epoch it read before that check. A claim
 * is answered from the cache only while the marker still equals the current epoch. Every write
 * that leaves a queue row pending (enqueue, retry, failure requeue, lease reaping, admin retry)
 * rotates the epoch once its transaction commits, which invalidates all markers at once.
 *
 * A marker never lives longer than EMPTY_TTL_SECONDS, nor past the next future `available_at`
 * of that lane, so a missed invalidation delays an item by at most EMPTY_TTL_SECONDS.
 */
class DiscordQueueClaimGate
{
    public const EMPTY_TTL_SECONDS = 30;

    public const CLAIM_REQUEST_TTL_SECONDS = 86_400;

    public const EPOCH_TTL_SECONDS = 604_800;

    public const EPOCH_KEY = 'discord:queue:claimable-epoch';

    public const EMPTY_LANE_KEY_PREFIX = 'discord:queue:empty-lane:';

    public const CLAIM_REQUEST_KEY_PREFIX = 'discord:queue:claim-request:';

    public function __construct(private readonly Repository $cache) {}

    /**
     * Whether a previous database check proved these lanes empty and nothing has been queued since.
     *
     * @param  list<DiscordQueueLane>  $lanes
     */
    public function isKnownEmpty(array $lanes, DiscordConnectionContext $connection, string $requestId): bool
    {
        $emptyLaneKey = $this->emptyLaneKey($lanes, $connection);
        $claimRequestKey = $this->claimRequestKey($requestId);

        try {
            $values = $this->cache->many([self::EPOCH_KEY, $emptyLaneKey, $claimRequestKey]);
        } catch (Throwable $exception) {
            Log::warning('Discord queue claim gate could not read the cache; falling back to the database.', [
                'exception' => $exception->getMessage(),
            ]);

            return false;
        }

        $epoch = $values[self::EPOCH_KEY] ?? null;
        $marker = $values[$emptyLaneKey] ?? null;

        return is_string($epoch)
            && is_string($marker)
            && hash_equals($epoch, $marker)
            && ($values[$claimRequestKey] ?? null) === null;
    }

    /**
     * Read the claimable epoch before a database check, creating one when none exists yet.
     */
    public function currentEpoch(): ?string
    {
        try {
            $epoch = $this->cache->get(self::EPOCH_KEY);
            if (is_string($epoch)) {
                return $epoch;
            }

            $this->cache->add(self::EPOCH_KEY, (string) Str::uuid(), self::EPOCH_TTL_SECONDS);
            $epoch = $this->cache->get(self::EPOCH_KEY);

            return is_string($epoch) ? $epoch : null;
        } catch (Throwable $exception) {
            Log::warning('Discord queue claim gate could not read the claimable epoch.', [
                'exception' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Record that the database found nothing claimable, bounded by the next availability time.
     *
     * @param  list<DiscordQueueLane>  $lanes
     */
    public function rememberEmpty(
        array $lanes,
        DiscordConnectionContext $connection,
        ?string $epoch,
        ?CarbonInterface $nextAvailableAt,
    ): void {
        if ($epoch === null) {
            return;
        }

        $ttl = self::EMPTY_TTL_SECONDS;
        if ($nextAvailableAt !== null) {
            $ttl = min($ttl, (int) floor(Carbon::now()->diffInSeconds($nextAvailableAt, false)));
        }
        if ($ttl < 1) {
            return;
        }

        try {
            $this->cache->put($this->emptyLaneKey($lanes, $connection), $epoch, $ttl);
        } catch (Throwable $exception) {
            Log::warning('Discord queue claim gate could not store an empty-lane marker.', [
                'exception' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Keep retries of a claim request that leased a command on the database path.
     */
    public function rememberClaimRequest(string $requestId): void
    {
        try {
            $this->cache->put($this->claimRequestKey($requestId), true, self::CLAIM_REQUEST_TTL_SECONDS);
        } catch (Throwable $exception) {
            Log::warning('Discord queue claim gate could not record a claim request.', [
                'exception' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Invalidate every empty-lane marker once the surrounding transaction commits.
     */
    public function invalidateAfterCommit(ConnectionInterface $database): void
    {
        $database->afterCommit(fn () => $this->invalidate());
    }

    public function invalidate(): void
    {
        try {
            $this->cache->put(self::EPOCH_KEY, (string) Str::uuid(), self::EPOCH_TTL_SECONDS);
        } catch (Throwable $exception) {
            Log::warning('Discord queue claim gate could not rotate the claimable epoch.', [
                'exception' => $exception->getMessage(),
                'bounded_delay_seconds' => self::EMPTY_TTL_SECONDS,
            ]);
        }
    }

    /** @param list<DiscordQueueLane> $lanes */
    public function emptyLaneKey(array $lanes, DiscordConnectionContext $connection): string
    {
        $laneValues = array_map(fn (DiscordQueueLane $lane): string => $lane->value, $lanes);
        sort($laneValues);

        return self::EMPTY_LANE_KEY_PREFIX.implode(':', [
            $connection->connectionId,
            $connection->applicationId,
            $connection->guildId,
            $connection->generation,
            implode(',', $laneValues),
        ]);
    }

    private function claimRequestKey(string $requestId): string
    {
        return self::CLAIM_REQUEST_KEY_PREFIX.strtolower($requestId);
    }
}
