<?php

namespace Tests\Feature\Finance;

use App\Http\Middleware\DiscordVerifiedMiddleware;
use App\Http\Middleware\EnsureMfaConfigured;
use App\Http\Middleware\EnsureUserIsVerified;
use App\Jobs\GenerateAccountStatementExport;
use App\Models\Account;
use App\Models\AccountStatementExport;
use App\Models\DirectDepositLog;
use App\Models\GrowthCircleDistribution;
use App\Models\ManualTransaction;
use App\Models\MMRAssistantPurchase;
use App\Models\Nation;
use App\Models\Transaction;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Finance\MemberAccountStatementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class MemberAccountStatementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([
            EnsureUserIsVerified::class,
            DiscordVerifiedMiddleware::class,
            EnsureMfaConfigured::class,
        ]);
    }

    public function test_member_can_filter_an_owned_statement_but_cannot_select_another_members_account(): void
    {
        [$member, $account] = $this->createMemberWithAccount(870001, 'Primary');
        [, $otherAccount] = $this->createMemberWithAccount(870002, 'Other member');
        $transaction = $this->createTransaction($account, 'deposit', false, 'Visible deposit');
        $pendingWithdrawal = $this->createTransaction($account, 'withdrawal', true, 'Pending withdrawal');
        $manual = $this->createManualAdjustment($account, 'Manual correction', 'MANUAL-REF');

        $response = $this->actingAs($member)->get(route('accounts.statements.index', [
            'account_id' => $account->id,
            'from' => now()->subDay()->toDateString(),
            'to' => now()->toDateString(),
            'type' => 'manual_adjustment',
            'status' => 'completed',
        ]));

        $response->assertOk()
            ->assertSee('Personal account statement')
            ->assertSee('Manual correction')
            ->assertSee('MANUAL-REF')
            ->assertDontSee("TX-{$transaction->id}")
            ->assertSee('value="manual_adjustment" selected', false)
            ->assertSee('value="completed" selected', false);

        $this->assertNotNull($manual->id);

        $this->actingAs($member)
            ->get(route('accounts.statements.index', ['account_id' => $otherAccount->id]))
            ->assertForbidden();

        $this->actingAs($member)
            ->post(route('accounts.statements.exports.store'), [
                'account_id' => $otherAccount->id,
            ])
            ->assertForbidden();

        $this->actingAs($member)
            ->get(route('accounts.statements.index', [
                'account_id' => $account->id,
                'from' => now()->subDay()->toDateString(),
                'to' => now()->toDateString(),
                'type' => 'withdrawal',
                'status' => 'pending',
            ]))
            ->assertOk()
            ->assertSee('Pending withdrawal')
            ->assertSee("TX-{$pendingWithdrawal->id}")
            ->assertDontSee('Visible deposit');
    }

    public function test_one_sided_date_filters_receive_safe_defaults(): void
    {
        [$member, $account] = $this->createMemberWithAccount(870011, 'Date defaults');

        $this->actingAs($member)
            ->get(route('accounts.statements.index', [
                'account_id' => $account->id,
                'from' => now()->subDay()->toDateString(),
            ]))
            ->assertOk()
            ->assertSee('name="to" value="'.now()->toDateString().'"', false);

        $this->actingAs($member)
            ->get(route('accounts.statements.index', [
                'account_id' => $account->id,
                'to' => now()->subDay()->toDateString(),
            ]))
            ->assertOk()
            ->assertSee('name="from" value="'.now()->subDays(90)->toDateString().'"', false);
    }

    public function test_print_statement_is_accessible_and_does_not_invent_period_balances(): void
    {
        [$member, $account] = $this->createMemberWithAccount(870003, 'Operations');
        $account->forceFill(['money' => 1234.56, 'coal' => 789])->save();
        $transaction = $this->createTransaction($account, 'withdrawal', false, 'Member withdrawal');

        $response = $this->actingAs($member)->get(route('accounts.statements.print', [
            'account_id' => $account->id,
            'from' => now()->subDay()->toDateString(),
            'to' => now()->toDateString(),
        ]));

        $response->assertOk()
            ->assertSee('<main aria-labelledby="print-statement-heading">', false)
            ->assertSee('<caption>Current observed resource balances, not period balances</caption>', false)
            ->assertSee('scope="col"', false)
            ->assertSee('Opening and closing balances unavailable')
            ->assertSee('must not be treated as the closing balance')
            ->assertSee("TX-{$transaction->id}")
            ->assertSee('$1,234.56');
    }

    public function test_statement_normalizes_direct_deposit_mmr_and_growth_circle_activity(): void
    {
        [$member, $account] = $this->createMemberWithAccount(870010, 'Program activity');
        $directDeposit = DirectDepositLog::query()->create([
            'nation_id' => $account->nation_id,
            'account_id' => $account->id,
            'bank_record_id' => 991001,
            'money' => 500,
            'coal' => 25,
        ]);
        $mmr = MMRAssistantPurchase::query()->create([
            'account_id' => $account->id,
            'total_spent' => 120,
            'allocation_mode' => MMRAssistantPurchase::ALLOCATION_MODE_AUTOMATIC,
            'coal' => 10,
        ]);
        $growth = GrowthCircleDistribution::query()->create([
            'nation_id' => $account->nation_id,
            'account_id' => $account->id,
            'enrollment_id' => null,
            'coal' => 8,
            'cycle_date' => now()->toDateString(),
        ]);

        $this->actingAs($member)
            ->get(route('accounts.statements.index', [
                'account_id' => $account->id,
                'from' => now()->subDay()->toDateString(),
                'to' => now()->toDateString(),
            ]))
            ->assertOk()
            ->assertSee("BANK-{$directDeposit->bank_record_id}")
            ->assertSee("MMR-{$mmr->id}")
            ->assertSee("GC-{$growth->id}")
            ->assertSee('Direct Deposit')
            ->assertSee('MMR purchase')
            ->assertSee('Growth Circle distribution');
    }

    public function test_synchronous_csv_escapes_formula_cells_and_contains_required_columns(): void
    {
        config()->set('finance.account_statements.sync_row_limit', 100);
        [$member, $account] = $this->createMemberWithAccount(870004, '+SUM(1,1)');
        $this->createManualAdjustment(
            $account,
            '=HYPERLINK("https://bad.test","open")',
            '=1+1'
        );

        $response = $this->actingAs($member)->post(route('accounts.statements.exports.store'), [
            'account_id' => $account->id,
            'from' => now()->subDay()->toDateString(),
            'to' => now()->toDateString(),
            'type' => 'manual_adjustment',
            'status' => 'completed',
        ]);

        $response->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('no-store', (string) $response->headers->get('cache-control'));
        $this->assertStringContainsString('private', (string) $response->headers->get('cache-control'));

        $csv = $response->streamedContent();

        $this->assertStringContainsString('"Transaction type",Status', $csv);
        $this->assertStringContainsString('"Reference ID","Source record",Description,Money,Coal,Oil', $csv);
        $this->assertStringContainsString("'+SUM(1,1)", $csv);
        $this->assertStringContainsString("'=1+1", $csv);
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertDatabaseCount('account_statement_exports', 0);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'account_statement_downloaded',
            'subject_type' => Account::class,
            'subject_id' => (string) $account->id,
        ]);
    }

    public function test_large_export_request_is_owner_scoped_and_deduplicated(): void
    {
        config()->set('finance.account_statements.sync_row_limit', 0);
        Queue::fake();
        [$member, $account] = $this->createMemberWithAccount(870005, 'Large export');
        $this->createTransaction($account, 'deposit');
        $payload = [
            'account_id' => $account->id,
            'from' => now()->subDay()->toDateString(),
            'to' => now()->toDateString(),
        ];

        $first = $this->actingAs($member)->post(route('accounts.statements.exports.store'), $payload);
        $export = AccountStatementExport::query()->sole();

        $first->assertRedirect(route('accounts.statements.exports.show', $export));

        $this->actingAs($member)
            ->post(route('accounts.statements.exports.store'), $payload)
            ->assertRedirect(route('accounts.statements.exports.show', $export));

        $this->assertDatabaseCount('account_statement_exports', 1);
        $this->assertSame(AccountStatementExport::STATUS_PENDING, $export->status);
        $this->assertSame(AccountStatementExport::ACTIVE_KEY_VALUE, $export->active_key);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'account_statement_export_requested',
            'subject_type' => AccountStatementExport::class,
            'subject_id' => (string) $export->id,
        ]);
        Queue::assertPushed(GenerateAccountStatementExport::class, function (GenerateAccountStatementExport $job) use ($export): bool {
            return $job->exportId === $export->id;
        });
        Queue::assertPushedTimes(GenerateAccountStatementExport::class, 1);
    }

    public function test_queued_export_is_private_expiring_downloadable_and_job_is_idempotent(): void
    {
        Storage::fake('local');
        config()->set('finance.account_statements.availability_hours', 2);
        [$member, $account] = $this->createMemberWithAccount(870006, 'Private export');
        [, $otherAccount] = $this->createMemberWithAccount(870007, 'Unauthorized');
        $this->createTransaction($account, 'deposit', false, 'Queued row');
        $filters = app(MemberAccountStatementService::class)->normalizeFilters([
            'from' => now()->subDay()->toDateString(),
            'to' => now()->toDateString(),
        ]);
        $export = AccountStatementExport::query()->create([
            'user_id' => $member->id,
            'account_id' => $account->id,
            'status' => AccountStatementExport::STATUS_PENDING,
            'request_fingerprint' => app(MemberAccountStatementService::class)->fingerprint($filters),
            'filters' => $filters,
        ]);
        $job = new GenerateAccountStatementExport($export->id);

        $job->handle(app(MemberAccountStatementService::class), app(AuditLogger::class));
        $completedAt = $export->fresh()->completed_at;
        $job->handle(app(MemberAccountStatementService::class), app(AuditLogger::class));
        $export->refresh();

        $this->assertSame(AccountStatementExport::STATUS_COMPLETED, $export->status);
        $this->assertSame(1, $export->row_count);
        $this->assertTrue($export->expires_at->isFuture());
        $this->assertTrue($completedAt->equalTo($export->completed_at));
        Storage::disk('local')->assertExists($export->path);

        $download = $this->actingAs($member)->get(route('accounts.statements.exports.download', $export));
        $download->assertOk();
        $this->assertStringContainsString('no-store', (string) $download->headers->get('cache-control'));
        $this->assertStringContainsString('private', (string) $download->headers->get('cache-control'));
        $this->assertStringContainsString('Queued row', $download->streamedContent());

        $otherMember = User::query()->where('nation_id', $otherAccount->nation_id)->sole();
        $this->actingAs($otherMember)
            ->get(route('accounts.statements.exports.show', $export))
            ->assertNotFound();
        $this->actingAs($otherMember)
            ->get(route('accounts.statements.exports.download', $export))
            ->assertNotFound();
    }

    public function test_expired_stale_and_failed_exports_have_clear_terminal_states_and_cleanup(): void
    {
        Storage::fake('local');
        config()->set('finance.account_statements.stale_processing_hours', 1);
        config()->set('finance.account_statements.history_retention_days', 1);
        [$member, $account] = $this->createMemberWithAccount(870008, 'Lifecycle');
        $filters = app(MemberAccountStatementService::class)->normalizeFilters([]);
        $expired = AccountStatementExport::query()->create([
            'user_id' => $member->id,
            'account_id' => $account->id,
            'status' => AccountStatementExport::STATUS_COMPLETED,
            'request_fingerprint' => hash('sha256', 'expired'),
            'filters' => $filters,
            'path' => "account-statements/{$member->id}/expired.csv",
            'row_count' => 5,
            'completed_at' => now()->subDay(),
            'expires_at' => now()->subMinute(),
        ]);
        Storage::disk('local')->put($expired->path, 'private');

        $this->actingAs($member)
            ->get(route('accounts.statements.exports.show', $expired))
            ->assertOk()
            ->assertSee('Export expired');

        $expired->refresh();
        $this->assertSame(AccountStatementExport::STATUS_EXPIRED, $expired->status);
        $this->actingAs($member)
            ->get(route('accounts.statements.exports.download', $expired))
            ->assertGone();

        $stale = AccountStatementExport::query()->create([
            'user_id' => $member->id,
            'account_id' => $account->id,
            'status' => AccountStatementExport::STATUS_PENDING,
            'request_fingerprint' => hash('sha256', 'stale'),
            'filters' => $filters,
        ]);
        $stale->forceFill(['created_at' => now()->subHours(2)])->saveQuietly();

        $oldFailure = AccountStatementExport::query()->create([
            'user_id' => $member->id,
            'account_id' => $account->id,
            'status' => AccountStatementExport::STATUS_FAILED,
            'request_fingerprint' => hash('sha256', 'old-failure'),
            'filters' => $filters,
            'failure_message' => 'Old failure',
            'failed_at' => now()->subDays(2),
        ]);
        $oldFailure->forceFill(['created_at' => now()->subDays(2), 'updated_at' => now()->subDays(2)])->saveQuietly();

        $this->artisan('account-statements:prune')->assertSuccessful();

        Storage::disk('local')->assertMissing("account-statements/{$member->id}/expired.csv");
        $this->assertNull($expired->fresh()->path);
        $this->assertSame(AccountStatementExport::STATUS_FAILED, $stale->fresh()->status);
        $this->assertNull($stale->fresh()->active_key);
        $this->assertDatabaseMissing('account_statement_exports', ['id' => $oldFailure->id]);

        $this->actingAs($member)
            ->get(route('accounts.statements.exports.show', $stale))
            ->assertOk()
            ->assertSee('Export failed')
            ->assertSee('The export did not finish. Create a new export.');
    }

    public function test_job_failure_marks_a_recoverable_failure_without_exposing_exception_details(): void
    {
        Storage::fake('local');
        [$member, $account] = $this->createMemberWithAccount(870009, 'Failed export');
        $export = AccountStatementExport::query()->create([
            'user_id' => $member->id,
            'account_id' => $account->id,
            'status' => AccountStatementExport::STATUS_PENDING,
            'request_fingerprint' => hash('sha256', 'failure'),
            'filters' => app(MemberAccountStatementService::class)->normalizeFilters([]),
        ]);

        (new GenerateAccountStatementExport($export->id))->failed(new RuntimeException('secret database details'));

        $export->refresh();
        $this->assertSame(AccountStatementExport::STATUS_FAILED, $export->status);
        $this->assertNull($export->active_key);
        $this->assertStringNotContainsString('secret database details', (string) $export->failure_message);

        $this->actingAs($member)
            ->get(route('accounts.statements.exports.show', $export))
            ->assertOk()
            ->assertSee('Try again or narrow the date range')
            ->assertDontSee('secret database details');
    }

    public function test_worker_does_not_resurrect_an_export_marked_failed_during_generation(): void
    {
        Storage::fake('local');
        [$member, $account] = $this->createMemberWithAccount(870012, 'Terminal export');
        $filters = app(MemberAccountStatementService::class)->normalizeFilters([]);
        $export = AccountStatementExport::query()->create([
            'user_id' => $member->id,
            'account_id' => $account->id,
            'status' => AccountStatementExport::STATUS_PENDING,
            'request_fingerprint' => hash('sha256', 'terminal-export'),
            'filters' => $filters,
        ]);
        $statements = new class($export->id) extends MemberAccountStatementService
        {
            public function __construct(private readonly int $exportId) {}

            public function writeCsv($handle, Account $account, array $filters): int
            {
                fwrite($handle, "Reference ID\nTX-1\n");

                AccountStatementExport::query()->findOrFail($this->exportId)->forceFill([
                    'status' => AccountStatementExport::STATUS_FAILED,
                    'failure_message' => 'Cleanup marked this export as stale.',
                    'failed_at' => now(),
                ])->save();

                return 1;
            }
        };

        (new GenerateAccountStatementExport($export->id))->handle($statements, app(AuditLogger::class));

        $export->refresh();
        $this->assertSame(AccountStatementExport::STATUS_FAILED, $export->status);
        $this->assertNull($export->active_key);
        Storage::disk('local')->assertMissing(
            "account-statements/{$member->id}/{$export->public_id}.csv"
        );
        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'account_statement_export_completed',
            'subject_id' => (string) $export->id,
        ]);
    }

    /**
     * @return array{0: User, 1: Account}
     */
    private function createMemberWithAccount(int $nationId, string $accountName): array
    {
        $nation = Nation::factory()->create([
            'id' => $nationId,
            'alliance_id' => 777,
            'alliance_position' => 'MEMBER',
            'alliance_position_id' => 1,
        ]);
        $user = User::factory()->verified()->create(['nation_id' => $nation->id]);
        $account = new Account;
        $account->nation_id = $nation->id;
        $account->name = $accountName;
        $account->save();

        return [$user, $account];
    }

    private function createTransaction(
        Account $account,
        string $type,
        bool $pending = false,
        ?string $note = null,
    ): Transaction {
        $transaction = new Transaction;
        $transaction->forceFill([
            'to_account_id' => $type === 'withdrawal' ? null : $account->id,
            'from_account_id' => $type === 'withdrawal' ? $account->id : null,
            'nation_id' => $account->nation_id,
            'transaction_type' => $type,
            'note' => $note,
            'money' => 125.50,
            'coal' => 10,
            'is_pending' => $pending,
            'requires_admin_approval' => $pending,
        ]);
        $transaction->save();

        return $transaction;
    }

    private function createManualAdjustment(
        Account $account,
        string $note,
        ?string $correlationId,
    ): ManualTransaction {
        return ManualTransaction::query()->create([
            'account_id' => $account->id,
            'admin_id' => null,
            'correlation_id' => $correlationId,
            'money' => 50,
            'coal' => -5,
            'note' => $note,
            'ip_address' => null,
        ]);
    }
}
