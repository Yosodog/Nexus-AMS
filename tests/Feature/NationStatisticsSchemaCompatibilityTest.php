<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class NationStatisticsSchemaCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_current_schema_contains_all_statistics_written_by_nation_sync(): void
    {
        $this->assertTrue(Schema::hasColumns('nations', [
            'money_looted',
            'total_infrastructure_destroyed',
            'total_infrastructure_lost',
        ]));
        $this->assertTrue(Schema::hasColumn('nation_military', 'spy_attacks'));
    }
}
