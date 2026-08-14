<?php

namespace Tests\Feature\Migrations;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class DiscordQueueV2CutoverMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_cutover_retires_active_legacy_work_and_preserves_bound_v2_work(): void
    {
        $migration = $this->migration();
        $migration->down();
        $now = now();

        DB::table('discord_queue')->insert([
            [
                'id' => '11111111-1111-4111-8111-111111111111',
                'action' => 'CITY_TIER_SYNC',
                'guild_id' => null,
                'lane' => 'legacy',
                'payload' => '{}',
                'status' => 'pending',
                'attempts' => 0,
                'priority' => 50,
                'available_at' => $now,
                'connection_id' => null,
                'application_id' => null,
                'connection_generation' => null,
                'dedupe_scope' => 'legacy',
                'completed_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => '22222222-2222-4222-8222-222222222222',
                'action' => 'WAR_ALERT',
                'guild_id' => '123456789012345678',
                'lane' => 'legacy',
                'payload' => '{}',
                'status' => 'processing',
                'attempts' => 1,
                'priority' => 50,
                'available_at' => $now,
                'connection_id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
                'application_id' => '223456789012345678',
                'connection_generation' => 3,
                'dedupe_scope' => 'legacy',
                'completed_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => '33333333-3333-4333-8333-333333333333',
                'action' => 'WAR_ROOM_ARCHIVE',
                'guild_id' => '123456789012345678',
                'lane' => 'side_effects',
                'payload' => '{}',
                'status' => 'complete',
                'attempts' => 1,
                'priority' => 50,
                'available_at' => $now,
                'connection_id' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
                'application_id' => '223456789012345678',
                'connection_generation' => 4,
                'dedupe_scope' => 'legacy',
                'completed_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => '44444444-4444-4444-8444-444444444444',
                'action' => 'MEMBER_PROFILE_SYNC',
                'guild_id' => '123456789012345678',
                'lane' => 'side_effects',
                'payload' => '{}',
                'status' => 'pending',
                'attempts' => 0,
                'priority' => 50,
                'available_at' => $now,
                'connection_id' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
                'application_id' => '223456789012345678',
                'connection_generation' => 5,
                'dedupe_scope' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc:5',
                'completed_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        $migration->up();

        $this->assertDatabaseHas('discord_queue', [
            'id' => '11111111-1111-4111-8111-111111111111',
            'lane' => 'side_effects',
            'status' => 'failed',
            'dedupe_scope' => 'historical:11111111-1111-4111-8111-111111111111',
        ]);
        $this->assertDatabaseHas('discord_queue', [
            'id' => '22222222-2222-4222-8222-222222222222',
            'lane' => 'alerts',
            'status' => 'failed',
            'dedupe_scope' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa:3',
        ]);
        $this->assertDatabaseHas('discord_queue', [
            'id' => '33333333-3333-4333-8333-333333333333',
            'status' => 'complete',
            'dedupe_scope' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb:4',
        ]);
        $this->assertDatabaseHas('discord_queue', [
            'id' => '44444444-4444-4444-8444-444444444444',
            'status' => 'pending',
            'dedupe_scope' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc:5',
        ]);
    }

    public function test_cutover_aborts_when_a_legacy_action_has_not_been_classified(): void
    {
        $migration = $this->migration();
        $migration->down();
        $now = now();

        DB::table('discord_queue')->insert([
            'id' => '55555555-5555-4555-8555-555555555555',
            'action' => 'UNCLASSIFIED_ACTION',
            'lane' => 'legacy',
            'payload' => '{}',
            'status' => 'complete',
            'attempts' => 1,
            'priority' => 50,
            'available_at' => $now,
            'dedupe_scope' => 'legacy',
            'completed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('UNCLASSIFIED_ACTION');

        $migration->up();
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_08_14_015744_enforce_v2_discord_queue_delivery.php');
    }
}
