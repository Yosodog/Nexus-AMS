<?php

namespace Tests\Feature\Migrations;

use App\Models\DepositImportCheckpoint;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DepositImportCheckpointMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_preserves_the_primary_cursor_and_starts_new_alliances_at_zero(): void
    {
        $migration = require database_path('migrations/2026_08_11_015137_create_deposit_import_checkpoints_table.php');
        $migration->down();

        config()->set('services.pw.alliance_id', 777);
        Setting::query()->updateOrCreate(
            ['key' => 'last_bank_record_id'],
            ['value' => '456'],
        );

        $migration->up();

        $this->assertDatabaseHas('deposit_import_checkpoints', [
            'alliance_id' => 777,
            'last_scanned_id' => 456,
        ]);
        $this->assertDatabaseMissing('deposit_import_checkpoints', [
            'alliance_id' => 888,
        ]);

        $this->assertSame(0, DepositImportCheckpoint::lastScannedId(888));
        $this->assertDatabaseHas('deposit_import_checkpoints', [
            'alliance_id' => 888,
            'last_scanned_id' => 0,
        ]);
    }
}
