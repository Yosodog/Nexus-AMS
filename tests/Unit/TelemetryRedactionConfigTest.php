<?php

namespace Tests\Unit;

use Laravel\Pulse\Recorders\SlowOutgoingRequests;
use Tests\TestCase;

class TelemetryRedactionConfigTest extends TestCase
{
    public function test_pulse_groups_politics_and_war_urls_without_query_secrets(): void
    {
        $url = 'https://api.politicsandwar.com/graphql?api_key=super-secret&foo=bar';
        $grouped = $url;

        foreach (config('pulse.recorders.'.SlowOutgoingRequests::class.'.groups', []) as $pattern => $replacement) {
            $candidate = preg_replace($pattern, $replacement, $url, count: $count);

            if ($count > 0 && $candidate !== null) {
                $grouped = $candidate;
                break;
            }
        }

        $this->assertSame('https://api.politicsandwar.com/graphql?[redacted]', $grouped);
        $this->assertStringNotContainsString('super-secret', $grouped);
    }
}
