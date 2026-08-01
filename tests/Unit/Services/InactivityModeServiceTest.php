<?php

namespace Tests\Unit\Services;

use App\Models\InactivityEvent;
use App\Models\Nation;
use App\Services\InactivityModeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

class InactivityModeServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_direct_deposit_opt_out_keeps_the_inactivity_episode_open(): void
    {
        $nation = Nation::factory()->create();
        $event = InactivityEvent::query()->create([
            'nation_id' => $nation->id,
            'episode_started_at' => now()->subDay(),
            'detected_inactive_at' => now()->subDay(),
        ]);

        $service = app(InactivityModeService::class);
        $service->recordDirectDepositOptOut($nation);

        $event->refresh();

        $this->assertNotNull($event->dd_opted_out_at);
        $this->assertNull($event->episode_ended_at);

        $optedOutAt = $event->dd_opted_out_at->toISOString();
        $service->recordDirectDepositOptOut($nation);

        $event->refresh();

        $this->assertSame($optedOutAt, $event->dd_opted_out_at->toISOString());
        $this->assertNull($event->episode_ended_at);
    }

    public function test_normalized_api_activity_timestamp_is_parsed_as_utc(): void
    {
        $previousTimezone = date_default_timezone_get();
        $parsed = null;

        try {
            date_default_timezone_set('America/Chicago');
            config(['app.timezone' => 'America/Chicago']);

            $method = new ReflectionMethod(InactivityModeService::class, 'parseLastActive');
            $parsed = $method->invoke(
                app(InactivityModeService::class),
                '2026-08-01 12:00:00',
            );
        } finally {
            date_default_timezone_set($previousTimezone);
        }

        $this->assertNotNull($parsed);
        $this->assertSame('UTC', $parsed->getTimezone()->getName());
        $this->assertSame('2026-08-01T12:00:00+00:00', $parsed->toIso8601String());
    }
}
