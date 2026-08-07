<?php

namespace Tests\Feature\Finance;

use App\Models\Account;
use App\Models\AllianceFinanceEntry;
use App\Models\Nation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\BuildsTestUsers;
use Tests\TestCase;

class AllianceFinanceLedgerFiltersTest extends TestCase
{
    use BuildsTestUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    public function test_finance_ledger_routes_require_financial_report_permission(): void
    {
        $admin = $this->createFinanceAdmin(false);

        $this->actingAs($admin)
            ->get(route('admin.finance.index'))
            ->assertForbidden();

        $this->actingAs($admin)
            ->get(route('admin.finance.day', ['date' => '2026-08-01']))
            ->assertForbidden();

        $this->actingAs($admin)
            ->get(route('admin.finance.export'))
            ->assertForbidden();
    }

    public function test_filters_are_restored_and_shared_by_results_summary_and_export(): void
    {
        $admin = $this->createFinanceAdmin();
        $account = $this->createAccount('Operations Vault');

        $matching = $this->createEntry([
            'direction' => AllianceFinanceEntry::DIRECTION_EXPENSE,
            'category' => 'grant',
            'description' => 'Steel rebuilding package',
            'account_id' => $account->id,
            'source_type' => 'grant_application',
            'source_id' => 4321,
            'money' => 125.50,
            'steel' => 25,
        ]);
        $this->createEntry([
            'direction' => AllianceFinanceEntry::DIRECTION_EXPENSE,
            'category' => 'grant',
            'description' => 'Money-only rebuilding package',
            'account_id' => $account->id,
            'money' => 900,
            'steel' => 0,
        ]);
        $this->createEntry([
            'direction' => AllianceFinanceEntry::DIRECTION_INCOME,
            'category' => 'tax',
            'description' => 'Unrelated tax intake',
            'money' => 500,
            'steel' => 25,
        ]);

        $query = [
            'from' => '2026-08-01',
            'to' => '2026-08-31',
            'direction' => 'expense',
            'categories' => ['grant'],
            'search' => 'Operations Vault',
            'resource' => 'steel',
            'sort' => 'amount',
            'sort_direction' => 'asc',
        ];

        $response = $this->actingAs($admin)->get(route('admin.finance.index', $query));

        $canonicalQuery = [
            'from' => '2026-08-01',
            'to' => '2026-08-31',
            'direction' => 'expense',
            'sort' => 'amount',
            'sort_direction' => 'asc',
            'categories' => ['grant'],
            'search' => 'Operations Vault',
            'resource' => 'steel',
        ];
        $clearSearchQuery = $canonicalQuery;
        unset($clearSearchQuery['search']);
        $descendingAmountQuery = $canonicalQuery;
        $descendingAmountQuery['sort_direction'] = 'desc';

        $response
            ->assertOk()
            ->assertSeeText('Steel rebuilding package')
            ->assertDontSeeText('Money-only rebuilding package')
            ->assertDontSeeText('Unrelated tax intake')
            ->assertSeeText('$125.50')
            ->assertSeeText('Search: Operations Vault')
            ->assertSeeText('Resource: Steel')
            ->assertSeeText('Category: Member Grants')
            ->assertSee('value="Operations Vault"', false)
            ->assertSee('value="steel" selected', false)
            ->assertSee('value="expense" selected', false)
            ->assertSee('aria-sort="ascending"', false)
            ->assertSee('aria-label="Clear Search: Operations Vault filter"', false)
            ->assertSee('href="'.e(route('admin.finance.index', $clearSearchQuery)).'"', false)
            ->assertSee('href="'.e(route('admin.finance.index', $descendingAmountQuery)).'"', false);

        $content = $response->getContent();
        $this->assertMatchesRegularExpression('/name="categories\[\]"\s+value="grant"[\s\S]*?checked/', $content);

        $export = $this->actingAs($admin)->get(route('admin.finance.export', $query));
        $export->assertOk();

        $rows = $this->csvRows($export->streamedContent());
        $header = array_shift($rows);
        $descriptionColumn = array_search('Description', $header, true);

        $this->assertNotFalse($descriptionColumn);
        $this->assertSame(
            [$matching->description],
            array_column($rows, $descriptionColumn),
        );
    }

    public function test_search_matches_counterparty_names_and_reference_ids(): void
    {
        $admin = $this->createFinanceAdmin();
        $nation = Nation::factory()->create([
            'nation_name' => 'Crimson Republic',
            'leader_name' => 'Ledger Tester',
        ]);

        $this->createEntry([
            'description' => 'Reference-searchable entry',
            'nation_id' => $nation->id,
            'source_type' => 'tax_record',
            'source_id' => 778899,
        ]);
        $this->createEntry(['description' => 'Excluded entry']);
        $this->createEntry(['description' => 'Literal % reference']);

        $baseQuery = [
            'from' => '2026-08-01',
            'to' => '2026-08-31',
        ];

        $this->actingAs($admin)
            ->get(route('admin.finance.index', $baseQuery + ['search' => 'Crimson Republic']))
            ->assertOk()
            ->assertSeeText('Reference-searchable entry')
            ->assertDontSeeText('Excluded entry');

        $this->actingAs($admin)
            ->get(route('admin.finance.index', $baseQuery + ['search' => '#778899']))
            ->assertOk()
            ->assertSeeText('Reference-searchable entry')
            ->assertDontSeeText('Excluded entry');

        $this->actingAs($admin)
            ->get(route('admin.finance.index', $baseQuery + ['search' => '%']))
            ->assertOk()
            ->assertSeeText('Literal % reference')
            ->assertDontSeeText('Excluded entry');
    }

    public function test_date_and_money_columns_are_sorted_server_side(): void
    {
        $admin = $this->createFinanceAdmin();

        $this->createEntry([
            'date' => '2026-08-01',
            'description' => 'Older expensive entry',
            'money' => 900,
        ]);
        $this->createEntry([
            'date' => '2026-08-03',
            'description' => 'Newer inexpensive entry',
            'money' => 10,
        ]);

        $baseQuery = [
            'from' => '2026-08-01',
            'to' => '2026-08-31',
        ];

        $this->actingAs($admin)
            ->get(route('admin.finance.index', $baseQuery + [
                'sort' => 'date',
                'sort_direction' => 'desc',
            ]))
            ->assertOk()
            ->assertSeeInOrder(['Newer inexpensive entry', 'Older expensive entry']);

        $this->actingAs($admin)
            ->get(route('admin.finance.index', $baseQuery + [
                'sort' => 'amount',
                'sort_direction' => 'asc',
            ]))
            ->assertOk()
            ->assertSeeInOrder(['Newer inexpensive entry', 'Older expensive entry']);
    }

    public function test_summary_uses_all_filtered_rows_instead_of_only_the_current_page(): void
    {
        $admin = $this->createFinanceAdmin();

        foreach (range(1, 51) as $index) {
            $this->createEntry([
                'description' => "Paged tax entry {$index}",
                'money' => 1,
            ]);
        }

        $this->actingAs($admin)
            ->get(route('admin.finance.index', [
                'from' => '2026-08-01',
                'to' => '2026-08-31',
                'categories' => ['tax'],
            ]))
            ->assertOk()
            ->assertSeeText('51 transactions')
            ->assertSeeText('$51.00');
    }

    public function test_filtered_empty_state_is_explicit(): void
    {
        $admin = $this->createFinanceAdmin();
        $this->createEntry(['description' => 'Existing ledger transaction']);

        $this->actingAs($admin)
            ->get(route('admin.finance.index', [
                'from' => '2026-08-01',
                'to' => '2026-08-31',
                'search' => 'no such reference',
            ]))
            ->assertOk()
            ->assertSeeText('No transactions match these filters')
            ->assertSeeText('Clear a filter or select another date range to continue.');
    }

    private function createFinanceAdmin(bool $withPermission = true): User
    {
        $admin = $this->createVerifiedAdmin([
            'nation_id' => fake()->unique()->numberBetween(700_000, 799_999),
        ]);
        $this->attachDiscordAccount($admin);

        return $withPermission
            ? $this->grantPermissions($admin, ['view-financial-reports'])
            : $admin;
    }

    private function createAccount(string $name): Account
    {
        $account = new Account;
        $account->name = $name;
        $account->save();

        return $account;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createEntry(array $attributes = []): AllianceFinanceEntry
    {
        return AllianceFinanceEntry::query()->create([
            'date' => '2026-08-15',
            'direction' => AllianceFinanceEntry::DIRECTION_INCOME,
            'category' => 'tax',
            'description' => 'Ledger entry',
            'money' => 25,
            ...$attributes,
        ]);
    }

    /**
     * @return array<int, array<int, string|null>>
     */
    private function csvRows(string $csv): array
    {
        return collect(preg_split('/\r\n|\n|\r/', trim($csv)))
            ->filter()
            ->map(static fn (string $row): array => str_getcsv($row))
            ->values()
            ->all();
    }
}
