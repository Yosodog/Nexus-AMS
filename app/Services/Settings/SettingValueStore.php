<?php

namespace App\Services\Settings;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Reads and writes rows of the `settings` table.
 *
 * Reads are served from a snapshot of the whole table kept in the cache store:
 *
 * - `settings:generation` holds an opaque token (no TTL). Every write replaces it.
 * - `settings:snapshot:v1:{token}` holds `['loaded_at' => int, 'values' => array<string, mixed>]`
 *   and expires after SNAPSHOT_TTL_SECONDS, which is the safety net for rows edited directly in the
 *   database.
 *
 * Because the snapshot key embeds the token, a reader that loaded rows just before a concurrent write can
 * only ever store its result under the previous token, so it can never overwrite fresher data.
 *
 * Each process also keeps the snapshot it last used. That copy is only trusted while the generation token in
 * the cache still matches (one small cache read per call) and while it is younger than SNAPSHOT_TTL_SECONDS,
 * so a write in any process is visible to the next read in every other process and the copy can never
 * outlive the cache entry it came from.
 *
 * Use getFresh() for values that are mutated by the code reading them (cursors, counters, "last processed"
 * markers, read-then-write guards): it always reads the database.
 */
class SettingValueStore
{
    public const GENERATION_KEY = 'settings:generation';

    public const SNAPSHOT_KEY_PREFIX = 'settings:snapshot:v1:';

    public const SNAPSHOT_TTL_SECONDS = 300;

    private const WARNING_INTERVAL_SECONDS = 60;

    /**
     * @var array{generation: string, loaded_at: int, values: array<string, mixed>}|null
     */
    private ?array $memo = null;

    private int $pendingWrites = 0;

    private int $lastWarningAt = 0;

    public function get(string $key): mixed
    {
        $values = $this->values();

        if ($values === null) {
            return $this->getFresh($key);
        }

        return $values[$key] ?? null;
    }

    /**
     * Read straight from the database, bypassing and never populating the cache.
     */
    public function getFresh(string $key): mixed
    {
        return Setting::query()->where('key', $key)->value('value');
    }

    public function set(string $key, mixed $value): void
    {
        Setting::query()->updateOrCreate(
            ['key' => $key],
            ['value' => $value],
        );
    }

    public function getString(string $key, string $default): string
    {
        $value = $this->get($key);

        if (is_null($value) || $value === '') {
            $value = $this->getFresh($key);
        }

        if (is_null($value) || $value === '') {
            $this->set($key, $default);

            return $default;
        }

        return (string) $value;
    }

    public function getStringWithoutPersisting(string $key, string $default): string
    {
        $value = $this->get($key);

        return is_null($value) || $value === '' ? $default : (string) $value;
    }

    /**
     * Whether a SQL statement inserts into, updates, deletes from, truncates or drops the settings table.
     *
     * Used by the query listener that keeps the cache honest for writes that bypass this class
     * (model saves, query-builder bulk writes, DB::table() calls, seeders and migrations).
     */
    public static function isSettingsWrite(string $sql): bool
    {
        if (stripos($sql, 'settings') === false) {
            return false;
        }

        return preg_match(
            '/^\s*(?:insert\s+(?:or\s+\w+\s+|ignore\s+)?into|replace\s+into|update(?:\s+or\s+\w+)?|delete\s+from|truncate(?:\s+table)?|drop\s+table(?:\s+if\s+exists)?)\s+(?:[`"\[]?\w+[`"\]]?\.)?[`"\[]?settings[`"\]]?(?![\w`"\]])/i',
            $sql,
        ) === 1;
    }

    /**
     * Drop every cached copy so the next read reloads the table.
     */
    public function flush(): void
    {
        $this->memo = null;

        try {
            Cache::forever(self::GENERATION_KEY, $this->newGeneration());
        } catch (Throwable $exception) {
            Log::error('Unable to invalidate the settings cache.', [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Record that a statement just changed the settings table on this connection.
     *
     * The cache is only invalidated once the surrounding transaction commits, so other processes never cache
     * rows that might still be rolled back. Until then this process reads the database directly so it sees
     * its own uncommitted changes.
     */
    public function noteWrite(): void
    {
        $this->memo = null;
        $this->pendingWrites++;

        DB::afterCommit(function (): void {
            $this->pendingWrites = max(0, $this->pendingWrites - 1);
            $this->flush();
        });
    }

    /**
     * @return array<string, mixed>|null null when the cache must not be used
     */
    private function values(): ?array
    {
        if ($this->pendingWrites > 0) {
            if (DB::transactionLevel() > 0) {
                return null;
            }

            $this->pendingWrites = 0;
            $this->flush();
        }

        $now = now()->getTimestamp();

        try {
            $generation = $this->currentGeneration();

            if ($this->memo !== null
                && $this->memo['generation'] === $generation
                && $now < $this->memo['loaded_at'] + self::SNAPSHOT_TTL_SECONDS) {
                return $this->memo['values'];
            }

            $snapshot = Cache::get(self::SNAPSHOT_KEY_PREFIX.$generation);
        } catch (Throwable $exception) {
            $this->memo = null;
            $this->warnCacheUnavailable($exception);

            return null;
        }

        if (! is_array($snapshot) || ! isset($snapshot['loaded_at']) || ! is_array($snapshot['values'] ?? null)) {
            $snapshot = [
                'loaded_at' => $now,
                'values' => Setting::query()->pluck('value', 'key')->all(),
            ];

            try {
                Cache::put(self::SNAPSHOT_KEY_PREFIX.$generation, $snapshot, self::SNAPSHOT_TTL_SECONDS);
            } catch (Throwable $exception) {
                $this->warnCacheUnavailable($exception);
            }
        }

        $this->memo = [
            'generation' => $generation,
            'loaded_at' => (int) $snapshot['loaded_at'],
            'values' => $snapshot['values'],
        ];

        return $this->memo['values'];
    }

    private function currentGeneration(): string
    {
        $generation = Cache::get(self::GENERATION_KEY);

        if (is_string($generation) && $generation !== '') {
            return $generation;
        }

        Cache::add(self::GENERATION_KEY, $this->newGeneration());

        $generation = Cache::get(self::GENERATION_KEY);

        return is_string($generation) && $generation !== '' ? $generation : $this->newGeneration();
    }

    private function newGeneration(): string
    {
        return Str::random(16);
    }

    private function warnCacheUnavailable(Throwable $exception): void
    {
        if (now()->getTimestamp() - $this->lastWarningAt < self::WARNING_INTERVAL_SECONDS) {
            return;
        }

        $this->lastWarningAt = now()->getTimestamp();

        Log::warning('Settings cache unavailable; reading settings from the database.', [
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
        ]);
    }
}
