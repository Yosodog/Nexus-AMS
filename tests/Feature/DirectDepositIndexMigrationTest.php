<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DirectDepositIndexMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_optimized_indexes_are_created_with_portable_schema_inspection(): void
    {
        $indexNames = collect(Schema::getIndexes('direct_deposit_logs'))->pluck('name');

        $this->assertTrue($indexNames->contains('ddl_nation_created_at_money_idx'));
        $this->assertTrue($indexNames->contains('ddl_account_created_at_idx'));
        $this->assertTrue($indexNames->contains('ddl_created_at_idx'));
    }
}
