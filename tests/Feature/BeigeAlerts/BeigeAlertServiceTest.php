<?php

namespace Tests\Feature\BeigeAlerts;

use App\Models\Alliance;
use App\Models\BeigeAlertAlliance;
use App\Models\DiscordQueue;
use App\Models\Nation;
use App\Models\NationMilitary;
use App\Models\Setting;
use App\Services\BeigeAlertService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BeigeAlertServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();
        $this->createTables();
    }

    public function test_it_queues_early_exit_alert_for_tracked_alliance(): void
    {
        Setting::query()->create(['key' => 'beige_alerts_enabled', 'value' => '1']);
        Setting::query()->create(['key' => 'beige_alerts_discord_channel_id', 'value' => '123456789']);

        Alliance::query()->create([
            'id' => 9988,
            'name' => 'Enemy Coalition',
        ]);

        BeigeAlertAlliance::query()->create([
            'alliance_id' => 9988,
        ]);

        Nation::query()->create([
            'id' => 2026,
            'alliance_id' => 9988,
            'nation_name' => 'Test Nation',
            'leader_name' => 'Tester',
            'num_cities' => 12,
            'score' => 1750.55,
            'beige_turns' => 0,
        ]);

        NationMilitary::query()->create([
            'nation_id' => 2026,
            'soldiers' => 145000,
            'tanks' => 2100,
            'aircraft' => 980,
            'ships' => 130,
            'missiles' => 4,
            'nukes' => 1,
            'spies' => 55,
        ]);

        app(BeigeAlertService::class)->maybeDispatchEarlyExitAlert(
            nationId: 2026,
            allianceId: 9988,
            previousBeigeTurns: 3,
            currentBeigeTurns: 0,
            detectedAt: CarbonImmutable::parse('2026-02-17 03:23:00')
        );

        $queued = DiscordQueue::query()->first();

        $this->assertNotNull($queued);
        $this->assertSame('BEIGE_ALERT', $queued->action);
        $this->assertSame('early_exit', $queued->payload['event_type']);
        $this->assertSame(2026, $queued->payload['nation']['id']);
        $this->assertSame('Tester', $queued->payload['nation']['leader_name']);
        $this->assertSame('123456789', $queued->payload['channel_id']);
    }

    private function createTables(): void
    {
        Schema::create('settings', function ($table): void {
            $table->id();
            $table->string('key')->unique();
            $table->string('value');
            $table->timestamps();
        });

        Schema::create('alliances', function ($table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->timestamps();
        });

        Schema::create('beige_alert_alliances', function ($table): void {
            $table->id();
            $table->unsignedBigInteger('alliance_id')->unique();
            $table->timestamps();
        });

        Schema::create('nations', function ($table): void {
            $table->id();
            $table->unsignedBigInteger('alliance_id')->nullable();
            $table->string('nation_name')->nullable();
            $table->string('leader_name')->nullable();
            $table->unsignedSmallInteger('num_cities')->default(0);
            $table->float('score')->default(0);
            $table->unsignedSmallInteger('beige_turns')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('nation_military', function ($table): void {
            $table->id();
            $table->unsignedBigInteger('nation_id')->unique();
            $table->unsignedInteger('soldiers')->default(0);
            $table->unsignedInteger('tanks')->default(0);
            $table->unsignedInteger('aircraft')->default(0);
            $table->unsignedInteger('ships')->default(0);
            $table->unsignedInteger('missiles')->default(0);
            $table->unsignedInteger('nukes')->default(0);
            $table->unsignedInteger('spies')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('discord_queue', function ($table): void {
            $table->uuid('id')->primary();
            $table->string('action');
            $table->json('payload');
            $table->string('status');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('available_at')->nullable();
            $table->timestamps();
        });
    }
}
