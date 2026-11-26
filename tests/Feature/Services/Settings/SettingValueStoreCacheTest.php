<?php

namespace Tests\Feature\Services\Settings;

use App\Models\Setting;
use App\Services\Settings\SettingValueStore;
use App\Services\SettingService;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class SettingValueStoreCacheTest extends TestCase
{
    use RefreshDatabase;

    private function store(): SettingValueStore
    {
        return app(SettingValueStore::class);
    }

    /**
     * A second store sharing the cache, standing in for another worker process.
     */
    private function otherProcess(): SettingValueStore
    {
        return new SettingValueStore;
    }

    private function settingsSelects(callable $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $callback();

        $count = collect(DB::getQueryLog())
            ->filter(fn (array $query): bool => stripos($query['query'], 'select') === 0 && stripos($query['query'], '"settings"') !== false)
            ->count();

        DB::disableQueryLog();

        return $count;
    }

    private function rawWrite(string $sql): void
    {
        DB::connection()->getPdo()->exec($sql);
    }

    public function test_repeated_reads_issue_a_single_query(): void
    {
        Setting::query()->create(['key' => 'alpha', 'value' => 'one']);
        Setting::query()->create(['key' => 'beta', 'value' => 'two']);

        $selects = $this->settingsSelects(function (): void {
            $this->assertSame('one', $this->store()->get('alpha'));
            $this->assertSame('two', $this->store()->get('beta'));
            $this->assertSame('one', SettingService::getValue('alpha'));
            $this->assertNull($this->store()->get('missing'));
            $this->assertSame('fallback', $this->store()->getStringWithoutPersisting('missing', 'fallback'));
        });

        $this->assertSame(1, $selects);
    }

    public function test_a_second_process_loads_the_shared_snapshot_without_querying(): void
    {
        Setting::query()->create(['key' => 'alpha', 'value' => 'one']);
        $this->store()->get('alpha');

        $selects = $this->settingsSelects(function (): void {
            $this->assertSame('one', $this->otherProcess()->get('alpha'));
        });

        $this->assertSame(0, $selects);
    }

    public function test_missing_empty_zero_and_null_values_keep_their_identity(): void
    {
        $this->store()->set('empty', '');
        $this->store()->set('zero_string', '0');
        $this->store()->set('zero_int', 0);
        $this->store()->set('false_bool', false);

        $this->assertNull($this->store()->get('missing'));
        $this->assertSame('', $this->store()->get('empty'));
        $this->assertSame('0', $this->store()->get('zero_string'));
        $this->assertSame($this->store()->getFresh('zero_int'), $this->store()->get('zero_int'));
        $this->assertSame($this->store()->getFresh('false_bool'), $this->store()->get('false_bool'));
        $this->assertSame('0', (string) $this->store()->get('zero_int'));
        $this->assertSame('fallback', $this->store()->getStringWithoutPersisting('empty', 'fallback'));
        $this->assertSame('0', $this->store()->getStringWithoutPersisting('zero_string', 'fallback'));
        $this->assertNull(SettingService::getValue('milcom_counter_monitoring_enabled'));

        $this->store()->set('milcom_counter_monitoring_enabled', '0');

        $this->assertSame('0', SettingService::getValue('milcom_counter_monitoring_enabled'));
    }

    public function test_get_string_persists_the_default_once_and_serves_it_from_cache(): void
    {
        $this->assertSame('default', $this->store()->getString('lazy', 'default'));
        $this->assertDatabaseHas('settings', ['key' => 'lazy', 'value' => 'default']);

        $selects = $this->settingsSelects(function (): void {
            $this->assertSame('default', $this->store()->getString('lazy', 'other'));
            $this->assertSame('default', $this->store()->get('lazy'));
        });

        $this->assertSame(1, $selects);
    }

    public function test_get_string_does_not_overwrite_a_row_the_cache_has_not_seen_yet(): void
    {
        $this->assertNull($this->store()->get('late'));

        $this->rawWrite("insert into settings (\"key\", \"value\", created_at, updated_at) values ('late', 'real', datetime('now'), datetime('now'))");

        $this->assertSame('real', $this->store()->getString('late', 'default'));
        $this->assertSame('real', $this->store()->getFresh('late'));
    }

    /**
     * @return array<string, array{0: callable(): void}>
     */
    public static function writePaths(): array
    {
        return [
            'store set' => [fn () => app(SettingValueStore::class)->set('probe', 'after')],
            'SettingService::setValue' => [fn () => SettingService::setValue('probe', 'after')],
            'typed SettingService setter' => [fn () => SettingService::setBackupsEnabled(true)],
            'model save' => [function (): void {
                $setting = Setting::query()->where('key', 'probe')->firstOrFail();
                $setting->value = 'after';
                $setting->save();
            }],
            'model update' => [fn () => Setting::query()->where('key', 'probe')->firstOrFail()->update(['value' => 'after'])],
            'query builder update' => [fn () => Setting::query()->where('key', 'probe')->update(['value' => 'after'])],
            'query builder delete' => [fn () => Setting::query()->where('key', 'probe')->delete()],
            'model delete' => [fn () => Setting::query()->where('key', 'probe')->firstOrFail()->delete()],
            'query builder insert' => [fn () => Setting::query()->insert(['key' => 'other', 'value' => 'x'])],
            'query builder upsert' => [fn () => Setting::query()->upsert([['key' => 'probe', 'value' => 'after']], ['key'], ['value'])],
            'insert or ignore' => [fn () => Setting::query()->insertOrIgnore(['key' => 'other', 'value' => 'x'])],
            'DB::table update' => [fn () => DB::table('settings')->where('key', 'probe')->update(['value' => 'after'])],
            'DB::table updateOrInsert' => [fn () => DB::table('settings')->updateOrInsert(['key' => 'probe'], ['value' => 'after'])],
            'DB::table delete' => [fn () => DB::table('settings')->where('key', 'probe')->delete()],
            'raw statement' => [fn () => DB::statement("update settings set value = 'after' where key = 'probe'")],
            'truncate' => [fn () => Setting::query()->truncate()],
        ];
    }

    #[DataProvider('writePaths')]
    public function test_every_write_path_is_visible_to_the_next_read_in_every_process(callable $write): void
    {
        Setting::query()->create(['key' => 'probe', 'value' => 'before']);

        $worker = $this->otherProcess();

        $this->assertSame('before', $this->store()->get('probe'));
        $this->assertSame('before', $worker->get('probe'));

        $write();

        $expected = $this->store()->getFresh('probe');

        $this->assertSame($expected, $this->store()->get('probe'));
        $this->assertSame($expected, $worker->get('probe'));
    }

    public function test_a_write_inside_a_transaction_is_seen_by_its_own_process_and_published_on_commit(): void
    {
        Setting::query()->create(['key' => 'probe', 'value' => 'before']);
        $worker = $this->otherProcess();
        $this->assertSame('before', $worker->get('probe'));

        DB::transaction(function (): void {
            $this->store()->set('probe', 'during');

            $this->assertSame('during', $this->store()->get('probe'));
            $this->assertSame('during', $this->store()->get('probe'));
        });

        $this->assertSame('during', $this->store()->get('probe'));
        $this->assertSame('during', $worker->get('probe'));
    }

    public function test_a_rolled_back_write_is_not_left_in_the_cache(): void
    {
        Setting::query()->create(['key' => 'probe', 'value' => 'before']);
        $this->assertSame('before', $this->store()->get('probe'));

        try {
            DB::transaction(function (): void {
                $this->store()->set('probe', 'during');

                $this->assertSame('during', $this->store()->get('probe'));

                throw new RuntimeException('abort');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame('before', $this->store()->get('probe'));
        $this->assertSame('before', $this->otherProcess()->get('probe'));
    }

    public function test_get_fresh_bypasses_the_cache(): void
    {
        Setting::query()->create(['key' => 'cursor', 'value' => '1']);
        $this->assertSame('1', $this->store()->get('cursor'));

        $this->rawWrite("update settings set value = '2' where key = 'cursor'");

        $this->assertSame('1', $this->store()->get('cursor'));
        $this->assertSame('2', $this->store()->getFresh('cursor'));
        $this->assertSame('1', $this->store()->get('cursor'));
    }

    public function test_mutable_state_getters_read_fresh_values(): void
    {
        Setting::query()->create(['key' => 'rebuilding_cycle_id', 'value' => '4']);
        Setting::query()->create(['key' => 'last_bank_record_id', 'value' => '10']);
        Setting::query()->create(['key' => 'last_rolling_nation_sync_batch_id', 'value' => 'batch-a']);
        Setting::query()->create(['key' => 'recruitment_current_cohort_key', 'value' => 'cohort-a']);
        $this->store()->get('rebuilding_cycle_id');

        $this->rawWrite("update settings set value = '5' where key = 'rebuilding_cycle_id'");
        $this->rawWrite("update settings set value = '11' where key = 'last_bank_record_id'");
        $this->rawWrite("update settings set value = 'batch-b' where key = 'last_rolling_nation_sync_batch_id'");
        $this->rawWrite("update settings set value = 'cohort-b' where key = 'recruitment_current_cohort_key'");

        $this->assertSame(6, SettingService::incrementRebuildingCycleId());
        $this->assertSame(11, SettingService::getLastScannedBankRecordId());
        $this->assertSame('batch-b', SettingService::getLastRollingNationSyncBatchId());
        $this->assertSame('cohort-b', SettingService::getRecruitmentCurrentCohortKey());
    }

    public function test_kill_switches_take_effect_on_write_without_waiting_for_the_ttl(): void
    {
        $worker = $this->otherProcess();

        $this->store()->set('backups_enabled', 1);
        $this->assertSame(1, (int) $worker->get('backups_enabled'));
        $this->assertTrue(SettingService::isBackupsEnabled());

        SettingService::setBackupsEnabled(false);

        $this->assertFalse(SettingService::isBackupsEnabled());
        $this->assertSame(0, (int) $worker->get('backups_enabled'));
    }

    public function test_rows_edited_directly_in_the_database_appear_once_the_snapshot_expires(): void
    {
        Setting::query()->create(['key' => 'manual', 'value' => 'before']);
        $this->assertSame('before', $this->store()->get('manual'));

        $this->rawWrite("update settings set value = 'after' where key = 'manual'");

        $this->travel(SettingValueStore::SNAPSHOT_TTL_SECONDS - 5)->seconds();
        $this->assertSame('before', $this->store()->get('manual'));

        $this->travel(10)->seconds();
        $this->assertSame('after', $this->store()->get('manual'));
    }

    public function test_a_cold_or_flushed_cache_reloads_from_the_database(): void
    {
        Setting::query()->create(['key' => 'alpha', 'value' => 'one']);
        $this->assertSame('one', $this->store()->get('alpha'));

        Cache::flush();

        $selects = $this->settingsSelects(function (): void {
            $this->assertSame('one', $this->store()->get('alpha'));
            $this->assertSame('one', $this->store()->get('alpha'));
        });

        $this->assertSame(1, $selects);
    }

    public function test_flush_invalidates_every_process(): void
    {
        Setting::query()->create(['key' => 'alpha', 'value' => 'one']);
        $worker = $this->otherProcess();
        $this->assertSame('one', $worker->get('alpha'));

        $this->rawWrite("update settings set value = 'two' where key = 'alpha'");
        $this->store()->flush();

        $this->assertSame('two', $worker->get('alpha'));
    }

    public function test_reads_and_writes_fall_back_to_the_database_when_the_cache_is_unavailable(): void
    {
        config(['cache.stores.broken' => ['driver' => 'broken']]);
        Cache::extend('broken', fn () => new Repository(new class extends ArrayStore
        {
            public function get($key): never
            {
                throw new RuntimeException('cache down');
            }

            public function many(array $keys): never
            {
                throw new RuntimeException('cache down');
            }

            public function put($key, $value, $seconds): never
            {
                throw new RuntimeException('cache down');
            }

            public function forever($key, $value): never
            {
                throw new RuntimeException('cache down');
            }
        }));
        config(['cache.default' => 'broken']);
        Cache::forgetDriver('broken');

        Setting::query()->create(['key' => 'alpha', 'value' => 'one']);

        $store = $this->otherProcess();

        $this->assertSame('one', $store->get('alpha'));
        $this->assertNull($store->get('missing'));

        $store->set('alpha', 'two');

        $this->assertSame('two', $store->get('alpha'));
        $this->assertSame('persisted', $store->getString('later', 'persisted'));
        $this->assertSame('persisted', $store->get('later'));
    }

    public function test_only_writes_to_the_settings_table_are_detected(): void
    {
        foreach ([
            'insert into "settings" ("key", "value") values (?, ?)',
            'insert into `settings` (`key`, `value`) values (?, ?)',
            'INSERT IGNORE INTO `settings` (`key`) values (?)',
            'insert or ignore into "settings" ("key") values (?)',
            'update `settings` set `value` = ? where `key` = ?',
            'update "settings" set "value" = ? where "key" = ?',
            'delete from `settings` where `key` = ?',
            'delete from "settings"',
            'truncate table `settings`',
            'drop table if exists `settings`',
            'insert into `db`.`settings` (`key`) values (?) on duplicate key update `value` = values(`value`)',
            'REPLACE INTO settings (`key`) values (?)',
        ] as $sql) {
            $this->assertTrue(SettingValueStore::isSettingsWrite($sql), $sql);
        }

        foreach ([
            'select * from "settings"',
            'select `value` from `settings` where `key` = ? limit 1',
            'insert into `mmr_settings` (`resource`) values (?)',
            'update `alert_user_settings` set `discord_enabled` = ?',
            'delete from `settings_history`',
            'insert into `accounts` (`name`) values (\'settings\')',
            'update `users` set `settings` = ?',
        ] as $sql) {
            $this->assertFalse(SettingValueStore::isSettingsWrite($sql), $sql);
        }
    }

    public function test_array_cache_is_used_by_the_test_environment(): void
    {
        $this->assertInstanceOf(ArrayStore::class, Cache::getStore());
    }
}
