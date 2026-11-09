<?php

namespace Tests\Feature\Database;

use App\Enums\LoanStatus;
use App\Models\Account;
use App\Models\Loan;
use App\Models\Nation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SqliteSchemaParityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('These assertions cover the SQLite schema.');
        }
    }

    #[Test]
    public function loans_accept_every_loan_status(): void
    {
        $account = $this->createAccount(1001, 'Main');

        foreach ([LoanStatus::Paid, LoanStatus::Missed] as $status) {
            $loan = Loan::query()->create([
                'nation_id' => $account->nation_id,
                'account_id' => $account->id,
                'amount' => 1000,
                'remaining_balance' => 0,
                'status' => $status,
            ]);

            $this->assertSame($status->value, DB::table('loans')->where('id', $loan->id)->value('status'));
        }
    }

    #[Test]
    public function loans_still_reject_unknown_statuses(): void
    {
        $account = $this->createAccount(1001, 'Main');

        $this->expectException(QueryException::class);

        DB::table('loans')->insert([
            'nation_id' => $account->nation_id,
            'account_id' => $account->id,
            'amount' => 1000,
            'remaining_balance' => 1000,
            'status' => 'legacy_review',
        ]);
    }

    #[Test]
    public function a_nation_cannot_hold_two_active_accounts_with_the_same_name(): void
    {
        $this->createAccount(1001, 'Main');

        $this->expectException(UniqueConstraintViolationException::class);

        $this->createAccount(1001, 'Main');
    }

    #[Test]
    public function account_names_may_repeat_across_nations_and_after_soft_deletion(): void
    {
        $original = $this->createAccount(1001, 'Main');
        $this->createAccount(1002, 'Main');

        $original->delete();
        $replacement = $this->createAccount(1001, 'Main');

        $this->assertSame('Main_1001', DB::table('accounts')->where('id', $replacement->id)->value('unique_name_key'));
        $this->assertNull(DB::table('accounts')->where('id', $original->id)->value('unique_name_key'));
    }

    #[Test]
    public function the_migration_renames_duplicate_active_accounts_before_adding_the_index(): void
    {
        $migration = $this->accountNameMigration();
        $migration->down();

        try {
            $first = $this->createAccount(1001, 'Main');
            $second = $this->createAccount(1001, 'Main');
            $deleted = $this->createAccount(1001, 'Main');
            $deleted->delete();
            $otherNation = $this->createAccount(1002, 'Main');
        } finally {
            $migration->up();
        }

        $this->assertSame('Main', $first->fresh()->name);
        $this->assertSame("Main #{$second->id}", $second->fresh()->name);
        $this->assertSame('Main', Account::withTrashed()->find($deleted->id)->name);
        $this->assertSame('Main', $otherNation->fresh()->name);
    }

    private function createAccount(int $nationId, string $name): Account
    {
        if (! Nation::query()->whereKey($nationId)->exists()) {
            Nation::factory()->create(['id' => $nationId]);
        }

        $account = new Account;
        $account->nation_id = $nationId;
        $account->name = $name;
        $account->save();

        return $account;
    }

    private function accountNameMigration(): Migration
    {
        return require database_path('migrations/2026_11_09_012512_add_sqlite_unique_name_key_to_accounts.php');
    }
}
