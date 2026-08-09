<?php

namespace Tests\Feature\Finance;

use App\Models\AllianceFinanceEntry;
use App\Models\Taxes;
use App\Models\TaxImportCheckpoint;
use App\Models\User;
use App\Services\AllianceMembershipService;
use App\Services\Finance\AllianceFinanceService;
use App\Services\TaxDashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\BuildsTestUsers;
use Tests\TestCase;

class AdminTaxDashboardTest extends TestCase
{
    use BuildsTestUsers;
    use RefreshDatabase;

    private int $nextTaxRecordId = 1;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config()->set('app.timezone', 'UTC');
        config()->set('services.pw.alliance_id', 777);
        app(AllianceMembershipService::class)->clear();
        $this->travelTo(Carbon::parse('2026-08-06 12:00:00', 'UTC'));
    }

    public function test_metrics_reconcile_with_the_tax_filtered_ledger_and_deep_link(): void
    {
        $admin = $this->createAdmin(['view-taxes', 'view-financial-reports']);
        $this->createHealthyCheckpoint();

        $this->createTaxRecord('2026-07-08 00:00:00', 120, ['coal' => 40, 'steel' => 10]);
        $this->createTaxRecord('2026-08-06 12:00:00', 180, ['coal' => 60]);
        $this->createTaxRecord('2026-07-07 23:59:59', 150, ['coal' => 25]);

        $this->createLedgerEntry('2026-07-08', 120, ['coal' => 40, 'steel' => 10]);
        $this->createLedgerEntry('2026-08-06', 180, ['coal' => 60]);
        $this->createLedgerEntry('2026-08-06', 999, ['category' => 'loan_interest']);

        $dashboard = app(TaxDashboardService::class)->getDashboard();
        $ledgerTotals = app(AllianceFinanceService::class)->getTotals(
            $dashboard['period']['starts_at'],
            $dashboard['period']['ends_at'],
            ['categories' => ['tax'], 'direction' => 'income'],
        );
        $ledgerCoal = (float) AllianceFinanceEntry::query()
            ->whereBetween('date', [
                $dashboard['period']['starts_at'],
                $dashboard['period']['ends_at'],
            ])
            ->where('category', 'tax')
            ->sum('coal');

        $this->assertSame(300.0, $dashboard['period']['total_money']);
        $this->assertSame(2, $dashboard['period']['record_count']);
        $this->assertSame(10.0, $dashboard['period']['average_daily_money']);
        $this->assertSame(100.0, $dashboard['period']['resource_totals']['coal']);
        $this->assertCount(30, $dashboard['daily_resource_totals']['money']);
        $this->assertSame(
            ['day' => '2026-07-08', 'total' => 120.0],
            $dashboard['daily_resource_totals']['money'][0],
        );
        $this->assertSame(
            ['day' => '2026-08-06', 'total' => 180.0],
            $dashboard['daily_resource_totals']['money'][29],
        );
        $this->assertSame(
            ['day' => '2026-07-09', 'total' => 0.0],
            $dashboard['daily_resource_totals']['coal'][1],
        );
        $this->assertSame(150.0, $dashboard['trend']['previous_money']);
        $this->assertSame(100.0, $dashboard['trend']['percent_change']);
        $this->assertSame($dashboard['period']['total_money'], $ledgerTotals['income']);
        $this->assertSame($dashboard['period']['resource_totals']['coal'], $ledgerCoal);

        $ledgerUrl = route('admin.finance.index', [
            'from' => '2026-07-08',
            'to' => '2026-08-06',
            'direction' => 'income',
            'categories' => ['tax'],
        ]);
        parse_str((string) parse_url($ledgerUrl, PHP_URL_QUERY), $ledgerQuery);

        $this->assertSame([
            'from' => '2026-07-08',
            'to' => '2026-08-06',
            'direction' => 'income',
            'categories' => ['tax'],
        ], $ledgerQuery);

        $this->actingAs($admin)
            ->get(route('admin.taxes'))
            ->assertOk()
            ->assertSeeText('$300.00')
            ->assertSeeText('100.0% (+$150.00)')
            ->assertSeeText('Tax data is current')
            ->assertSeeText('No collection exceptions are active.')
            ->assertSeeText('Current resource intake')
            ->assertSee('href="'.e($ledgerUrl).'"', false)
            ->assertSee('id="tax-chart-money"', false)
            ->assertSeeText('Resource trends')
            ->assertSeeText('Daily tax values')
            ->assertSeeText('Jul 8, 2026')
            ->assertDontSeeText('Top Resource');

        $this->actingAs($admin)
            ->get($ledgerUrl)
            ->assertOk()
            ->assertSeeText('Category: Taxes')
            ->assertSeeText('Alliance tax intake for 2026-07-08')
            ->assertSeeText('Alliance tax intake for 2026-08-06')
            ->assertDontSeeText('Unrelated ledger entry');
    }

    public function test_tax_only_staff_get_an_understandable_alternative_without_an_unauthorized_link(): void
    {
        $taxViewer = $this->createAdmin(['view-taxes']);
        $this->createHealthyCheckpoint();
        $ledgerUrl = route('admin.finance.index', [
            'from' => '2026-07-08',
            'to' => '2026-08-06',
            'direction' => 'income',
            'categories' => ['tax'],
        ]);

        $this->actingAs($taxViewer)
            ->get(route('admin.taxes'))
            ->assertOk()
            ->assertDontSeeText('View tax transactions')
            ->assertDontSee(e($ledgerUrl), false)
            ->assertSeeText('Finance ledger access is separate from tax-summary access.')
            ->assertSeeText('Ask a finance staff member for the filtered transaction report.');

        $this->actingAs($taxViewer)
            ->get($ledgerUrl)
            ->assertForbidden();
    }

    public function test_finance_permission_does_not_grant_tax_dashboard_access(): void
    {
        $financeViewer = $this->createAdmin(['view-financial-reports']);

        $this->actingAs($financeViewer)
            ->get(route('admin.taxes'))
            ->assertForbidden();
    }

    public function test_missing_sync_history_is_explicit(): void
    {
        $admin = $this->createAdmin(['view-taxes']);

        $this->actingAs($admin)
            ->get(route('admin.taxes'))
            ->assertOk()
            ->assertSeeText('Tax freshness is unavailable')
            ->assertSeeText('No successful refresh time is available.')
            ->assertSeeText('No tax records were recorded in this period.')
            ->assertSeeText('Alliance #777')
            ->assertSeeText('No successful tax sync has been recorded.')
            ->assertDontSeeText('Current resource intake');
    }

    public function test_stale_and_failed_sync_states_are_explicit_without_exposing_provider_errors(): void
    {
        $admin = $this->createAdmin(['view-taxes']);
        $checkpoint = TaxImportCheckpoint::query()->create([
            'alliance_id' => 777,
            'last_scanned_id' => 10,
            'last_attempted_at' => now()->subMinutes(121),
            'last_succeeded_at' => now()->subMinutes(121),
        ]);

        $this->actingAs($admin)
            ->get(route('admin.taxes'))
            ->assertOk()
            ->assertSeeText('Tax data is stale')
            ->assertSeeText('The latest successful tax sync is overdue.');

        $checkpoint->forceFill([
            'last_attempted_at' => now()->subMinutes(5),
            'last_failed_at' => now()->subMinutes(5),
            'last_error' => 'Provider secret token must never be rendered.',
        ])->save();

        $this->actingAs($admin)
            ->get(route('admin.taxes'))
            ->assertOk()
            ->assertSeeText('A tax sync needs attention')
            ->assertSeeText('The latest tax sync attempt failed.')
            ->assertDontSeeText('Provider secret token must never be rendered.');
    }

    /**
     * @param  array<int, string>  $permissions
     */
    private function createAdmin(array $permissions): User
    {
        $admin = $this->createVerifiedAdmin([
            'nation_id' => fake()->unique()->numberBetween(900_000, 999_999),
        ]);
        $this->attachDiscordAccount($admin);

        return $this->grantPermissions($admin, $permissions);
    }

    private function createHealthyCheckpoint(): TaxImportCheckpoint
    {
        return TaxImportCheckpoint::query()->create([
            'alliance_id' => 777,
            'last_scanned_id' => 10,
            'last_attempted_at' => now()->subMinutes(15),
            'last_succeeded_at' => now()->subMinutes(15),
        ]);
    }

    /**
     * @param  array<string, float|int>  $resources
     */
    private function createTaxRecord(string $date, float $money, array $resources = []): Taxes
    {
        return Taxes::query()->create([
            'id' => $this->nextTaxRecordId++,
            'date' => $date,
            'sender_id' => 123,
            'receiver_id' => 777,
            'receiver_type' => 2,
            'money' => $money,
            'tax_id' => 1,
            ...$resources,
        ]);
    }

    /**
     * @param  array<string, float|int|string>  $attributes
     */
    private function createLedgerEntry(string $date, float $money, array $attributes = []): AllianceFinanceEntry
    {
        return AllianceFinanceEntry::query()->create([
            'date' => $date,
            'direction' => AllianceFinanceEntry::DIRECTION_INCOME,
            'category' => 'tax',
            'description' => "Alliance tax intake for {$date}",
            'money' => $money,
            ...$attributes,
        ]);
    }
}
