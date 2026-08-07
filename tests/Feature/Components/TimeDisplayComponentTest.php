<?php

namespace Tests\Feature\Components;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class TimeDisplayComponentTest extends TestCase
{
    public function test_relative_time_has_an_exact_keyboard_and_screen_reader_fallback(): void
    {
        $serverNow = CarbonImmutable::parse('2026-08-06T20:00:00+00:00');
        $value = $serverNow->subHours(2);

        $html = Blade::render(
            '<x-time.display :value="$value" :server-now="$serverNow" label="Updated" show-exact />',
            compact('serverNow', 'value')
        );

        $this->assertStringContainsString('data-nexus-time-display', $html);
        $this->assertStringContainsString('data-time-state="server"', $html);
        $this->assertStringContainsString('datetime="2026-08-06T18:00:00+00:00"', $html);
        $this->assertStringContainsString('data-server-reference="2026-08-06T20:00:00+00:00"', $html);
        $this->assertStringContainsString('2h ago', $html);
        $this->assertStringContainsString('tabindex="0"', $html);
        $this->assertStringContainsString('aria-label="Updated. 2h ago. Exact time Aug 6, 2026', $html);
        $this->assertStringContainsString('6:00:00 PM', $html);
        $this->assertStringContainsString('data-time-exact', $html);
    }

    public function test_countdown_preserves_dst_offsets_and_a_server_rendered_fallback(): void
    {
        $serverNow = CarbonImmutable::parse('2026-03-08 01:30:00', 'America/Chicago');
        $target = $serverNow->addHour();

        $html = Blade::render(
            '<x-time.countdown :target="$target" :server-now="$serverNow" mode="local" :stale-after="900" />',
            compact('serverNow', 'target')
        );

        $this->assertSame('2026-03-08T03:30:00-05:00', $target->toIso8601String());
        $this->assertStringContainsString('data-nexus-time-countdown', $html);
        $this->assertStringContainsString('data-time-countdown-mode="local"', $html);
        $this->assertStringContainsString('data-time-target="2026-03-08T03:30:00-05:00"', $html);
        $this->assertStringContainsString('data-server-reference="2026-03-08T01:30:00-06:00"', $html);
        $this->assertStringContainsString('data-time-stale-after="900"', $html);
        $this->assertStringContainsString('1h 00m 00s', $html);
        $this->assertStringContainsString('Mar 8, 2026, 3:30:00 AM CDT', $html);
        $this->assertStringContainsString('role="timer"', $html);
        $this->assertStringContainsString('aria-live="polite"', $html);
    }

    public function test_pw_turn_mode_exposes_target_and_recovery_copy(): void
    {
        $serverNow = CarbonImmutable::parse('2026-08-06T20:30:00+00:00');
        $target = CarbonImmutable::parse('2026-08-06T22:00:00+00:00');

        $html = Blade::render(
            '<x-time.countdown :target="$target" :server-now="$serverNow" mode="pw-turn" />',
            compact('serverNow', 'target')
        );

        $this->assertStringContainsString('data-time-countdown-mode="pw-turn"', $html);
        $this->assertStringContainsString('Next P&amp;W turn', $html);
        $this->assertStringContainsString('1h 30m 00s', $html);
        $this->assertStringContainsString('Turn started — refresh for the next turn', $html);
        $this->assertStringContainsString('Device clock differs from the server; server time is being used.', $html);
        $this->assertStringContainsString('data-clock-skew-threshold="60"', $html);
    }

    public function test_invalid_values_keep_a_readable_no_javascript_fallback(): void
    {
        $html = Blade::render(
            '<x-time.display value="not-a-date" fallback="Unknown time" /> <x-time.countdown target="not-a-date" />'
        );

        $this->assertStringContainsString('Unknown time', $html);
        $this->assertStringContainsString('Target time unavailable', $html);
        $this->assertStringNotContainsString('data-nexus-time-display', $html);
        $this->assertStringNotContainsString('data-nexus-time-countdown', $html);
    }
}
