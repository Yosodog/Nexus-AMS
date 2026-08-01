<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RecruitmentMessageMigrationCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_renamed_migration_can_run_again_without_recreating_or_overwriting_the_table(): void
    {
        DB::table('recruitment_messages')
            ->where('type', 'primary')
            ->update(['message' => 'Customized recruitment message']);

        $migration = require database_path('migrations/2025_10_31_000030_create_recruitment_messages_table.php');
        $migration->up();

        $this->assertSame(
            'Customized recruitment message',
            DB::table('recruitment_messages')->where('type', 'primary')->value('message'),
        );
        $this->assertSame(2, DB::table('recruitment_messages')->count());
    }
}
