<?php

namespace Tests\Feature;

use App\Models\AllianceFinanceEntry;
use App\Models\Nation;
use App\Models\Taxes;
use App\Models\User;
use App\Services\AllianceMembershipService;
use App\Services\MemberStatsService;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsTestUsers;
use Tests\TestCase;

class AdminMemberTaxSummaryTest extends TestCase
{
    use BuildsTestUsers;
    use RefreshDatabase;

    private int $nextTaxRecordId = 1;

    public function test_total_taxes_uses_an_inclusive_rolling_utc_timestamp_window(): void
    {
        config()->set('app.timezone', 'America/Chicago');
        $this->travelTo(Carbon::parse('2026-08-06 12:00:00', 'America/Chicago'));

        $admin = $this->createAdmin(['view-members', 'view-taxes']);
        $nation = $this->createMemberNation();

        $this->createTaxRecord($nation->id, '2026-07-07 17:00:00', 100.25, ['coal' => 50_000]);
        $this->createTaxRecord($nation->id, '2026-07-20 02:00:00', 20.00);
        $this->createTaxRecord($nation->id, '2026-07-20 20:00:00', 30.25);
        $this->createTaxRecord($nation->id, '2026-08-06 17:00:00', 25.00);

        $this->createTaxRecord($nation->id, '2026-07-07 16:59:59', 900.00);
        $this->createTaxRecord($nation->id, '2026-08-06 17:00:01', 800.00);
        $this->createTaxRecord($nation->id + 1, '2026-07-20 12:00:00', 700.00);

        $stats = app(MemberStatsService::class)->getNationStats($nation, $admin);

        $this->assertSame(175.5, $stats['taxSummary']['total_money']);
        $this->assertSame('UTC', $stats['taxSummary']['timezone']);
        $this->assertSame('2026-07-07T17:00:00+00:00', $stats['taxSummary']['window_starts_at']->toAtomString());
        $this->assertSame('2026-08-06T17:00:00+00:00', $stats['taxSummary']['window_ends_at']->toAtomString());
        $this->assertSame('2026-08-06T17:00:00+00:00', $stats['taxSummary']['latest_recorded_at']->toAtomString());

        $this->actingAs($admin)
            ->get(route('admin.members.show', ['Nation' => $nation->id]))
            ->assertOk()
            ->assertSeeText('Total Taxes (30d)')
            ->assertSeeText('$175.50')
            ->assertSeeText('UTC window: Jul 7, 2026 17:00 through Aug 6, 2026 17:00.')
            ->assertSeeText('Latest included record: Aug 6, 2026 17:00 UTC.');
    }

    public function test_total_matches_the_tax_money_emitted_by_the_finance_ledger_export(): void
    {
        $this->travelTo(Carbon::parse('2026-08-06 17:00:00', 'UTC'));

        $admin = $this->createAdmin([
            'view-members',
            'view-financial-reports',
            'view-taxes',
        ]);
        $nation = $this->createMemberNation();

        $this->createTaxRecord($nation->id, '2026-08-01 10:00:00', 125.25);
        $this->createTaxRecord($nation->id, '2026-08-01 22:00:00', 300.25);

        AllianceFinanceEntry::query()->create([
            'date' => '2026-08-01',
            'direction' => AllianceFinanceEntry::DIRECTION_INCOME,
            'category' => 'tax',
            'description' => 'Alliance tax intake for 2026-08-01',
            'money' => 425.50,
            'source_type' => Taxes::class,
            'source_id' => 20260801,
        ]);
        AllianceFinanceEntry::query()->create([
            'date' => '2026-08-01',
            'direction' => AllianceFinanceEntry::DIRECTION_INCOME,
            'category' => 'grant_repayment',
            'description' => 'Unrelated ledger income',
            'money' => 999.00,
        ]);

        $stats = app(MemberStatsService::class)->getNationStats($nation, $admin);
        $response = $this->actingAs($admin)->get(route('admin.finance.export', [
            'from' => '2026-07-07',
            'to' => '2026-08-06',
            'direction' => 'income',
            'categories' => ['tax'],
        ]));

        $response->assertOk();
        $rows = collect(preg_split('/\r\n|\n|\r/', trim($response->streamedContent())))
            ->filter()
            ->map(fn (string $row): array => str_getcsv($row))
            ->values();
        $header = $rows->shift();
        $moneyColumn = array_search('Money', $header, true);

        $this->assertNotFalse($moneyColumn);
        $this->assertSame(
            $stats['taxSummary']['total_money'],
            $rows->sum(fn (array $row): float => (float) $row[$moneyColumn]),
        );
    }

    /**
     * @param  array<int, string>  $permissions
     */
    private function createAdmin(array $permissions): User
    {
        $admin = $this->createVerifiedAdmin(['nation_id' => fake()->unique()->numberBetween(800_000, 899_999)]);
        $this->attachDiscordAccount($admin);

        return $this->grantPermissions($admin, $permissions);
    }

    private function createMemberNation(): Nation
    {
        config()->set('services.pw.alliance_id', 777);
        app(AllianceMembershipService::class)->clear();

        return Nation::factory()->create([
            'alliance_id' => 777,
            'alliance_position' => 'MEMBER',
            'leader_name' => 'Tax Summary Test Leader',
        ]);
    }

    /**
     * @param  array<string, float|int>  $resources
     */
    private function createTaxRecord(
        int $senderId,
        CarbonInterface|string $recordedAt,
        float $money,
        array $resources = []
    ): Taxes {
        return Taxes::query()->create([
            'id' => $this->nextTaxRecordId++,
            'date' => $recordedAt,
            'sender_id' => $senderId,
            'receiver_id' => 777,
            'receiver_type' => 2,
            'money' => $money,
            'tax_id' => 1,
            ...$resources,
        ]);
    }
}
