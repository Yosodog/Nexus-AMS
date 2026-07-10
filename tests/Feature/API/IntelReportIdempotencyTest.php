<?php

namespace Tests\Feature\API;

use App\Models\IntelReport;
use App\Services\IntelReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IntelReportIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_duplicate_report_hash_returns_the_existing_report(): void
    {
        $service = app(IntelReportService::class);
        $payload = [
            'nation_name' => 'Example Nation',
            'raw_text' => "Example Nation\nMoney: \$1,000,000",
            'money' => 1_000_000,
            'was_detected' => false,
        ];

        $first = $service->store($payload, 'discord');
        $second = $service->store($payload, 'discord');

        $this->assertSame($first->id, $second->id);
        $this->assertSame($first->hash, $second->hash);
        $this->assertDatabaseCount('intel_reports', 1);
        $this->assertSame(1_000_000.0, (float) IntelReport::query()->firstOrFail()->money);
    }
}
