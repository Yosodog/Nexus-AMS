<?php

namespace Tests\Feature\Finance;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\FeatureTestCase;

class AllianceFinanceEntryUniquenessTest extends FeatureTestCase
{
    use RefreshDatabase;

    public function test_migration_keeps_the_first_sourced_entry_and_enforces_uniqueness(): void
    {
        $migration = require database_path('migrations/2026_08_06_034827_enforce_unique_alliance_finance_sources.php');
        $migration->down();

        $canonicalId = DB::table('alliance_finance_entries')->insertGetId($this->entry([
            'description' => 'Canonical entry',
            'source_type' => 'tax_record',
            'source_id' => 123,
        ]));

        DB::table('alliance_finance_entries')->insert($this->entry([
            'description' => 'Duplicate entry',
            'source_type' => 'tax_record',
            'source_id' => 123,
        ]));

        DB::table('alliance_finance_entries')->insert($this->entry([
            'description' => 'Different direction',
            'direction' => 'expense',
            'source_type' => 'tax_record',
            'source_id' => 123,
        ]));

        DB::table('alliance_finance_entries')->insert($this->entry([
            'description' => 'Unsourced entry one',
        ]));

        DB::table('alliance_finance_entries')->insert($this->entry([
            'description' => 'Unsourced entry two',
        ]));

        $migration->up();

        $this->assertDatabaseHas('alliance_finance_entries', [
            'id' => $canonicalId,
            'description' => 'Canonical entry',
        ]);
        $this->assertDatabaseMissing('alliance_finance_entries', [
            'description' => 'Duplicate entry',
        ]);
        $this->assertSame(1, DB::table('alliance_finance_entries')
            ->where('source_type', 'tax_record')
            ->where('source_id', 123)
            ->where('direction', 'income')
            ->where('category', 'tax')
            ->count());
        $this->assertSame(2, DB::table('alliance_finance_entries')
            ->whereNull('source_type')
            ->whereNull('source_id')
            ->count());

        $this->expectException(UniqueConstraintViolationException::class);

        DB::table('alliance_finance_entries')->insert($this->entry([
            'source_type' => 'tax_record',
            'source_id' => 123,
        ]));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function entry(array $overrides = []): array
    {
        return array_merge([
            'date' => '2026-08-01',
            'direction' => 'income',
            'category' => 'tax',
            'description' => null,
            'source_type' => null,
            'source_id' => null,
            'money' => 100,
        ], $overrides);
    }
}
