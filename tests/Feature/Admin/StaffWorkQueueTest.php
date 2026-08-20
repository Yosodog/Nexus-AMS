<?php

namespace Tests\Feature\Admin;

use App\Enums\ApplicationStatus;
use App\Models\Application;
use App\Models\StaffWorkQueueSavedView;
use App\Models\User;
use App\Services\StaffWorkQueue\StaffWorkItem;
use App\Services\StaffWorkQueue\StaffWorkQueueRegistry;
use App\Services\StaffWorkQueue\StaffWorkQueueSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsTestUsers;
use Tests\TestCase;

class StaffWorkQueueTest extends TestCase
{
    use BuildsTestUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('pending_requests.permissions', [
            'applications' => 'manage-applications',
            'loans' => 'manage-loans',
        ]);
        config()->set('pending_requests.cache_key', 'testing.feature.staff-work-queue');
        config()->set('pending_requests.projection_cache_key', 'testing.feature.staff-work-queue.projection');
        Cache::forget('testing.feature.staff-work-queue');
        Cache::forget('testing.feature.staff-work-queue.projection');
    }

    public function test_queue_requires_a_management_permission_and_never_renders_unauthorized_metadata(): void
    {
        $this->bindSources([
            $this->source('loans', 'Loans', 'manage-loans', [
                $this->item('loans', 11, 'Visible loan', now()->subHours(4), route('admin.loans.view', ['Loan' => 11])),
            ]),
            $this->source('applications', 'Applications', 'manage-applications', [
                $this->item('applications', 21, 'Classified applicant', now()->subHours(3), route('admin.applications.show', 21)),
            ]),
        ]);

        $viewOnly = $this->createAdmin(['view-loans']);
        $this->actingAs($viewOnly)
            ->get(route('admin.work-queue.index'))
            ->assertForbidden();

        $loanManager = $this->createAdmin(['view-loans', 'manage-loans']);
        $this->actingAs($loanManager)
            ->get(route('admin.work-queue.index'))
            ->assertOk()
            ->assertSee('Visible loan')
            ->assertSee(route('admin.loans.view', ['Loan' => 11]), false)
            ->assertDontSee('Classified applicant')
            ->assertDontSee('applications:21');
    }

    public function test_search_type_urgency_owner_and_age_sort_filters_persist_in_the_url(): void
    {
        $this->bindSources([
            $this->source('loans', 'Loans', 'manage-loans', [
                $this->item('loans', 1, 'Old Alpha loan', now()->subDays(5), route('admin.loans.view', ['Loan' => 1])),
                $this->item(
                    'loans',
                    2,
                    'Recent Beta loan',
                    now()->subHours(2),
                    route('admin.loans.view', ['Loan' => 2]),
                    ownerKey: 'nation:77',
                    ownerLabel: 'Beta Leader',
                ),
            ]),
        ]);
        $admin = $this->createAdmin(['view-loans', 'manage-loans']);

        $this->actingAs($admin)
            ->get(route('admin.work-queue.index', [
                'q' => 'Beta',
                'type' => 'loans',
                'urgency' => 'routine',
                'owner' => 'nation:77',
                'sort' => 'age',
                'direction' => 'asc',
            ]))
            ->assertOk()
            ->assertSee('Recent Beta loan')
            ->assertDontSee('Old Alpha loan')
            ->assertSee('value="Beta"', false)
            ->assertSee('value="nation:77" selected', false)
            ->assertSee('direction=desc', false);

        $this->actingAs($admin)
            ->get(route('admin.work-queue.index', ['q' => 'no match']))
            ->assertOk()
            ->assertSee('No work matches these filters')
            ->assertDontSee('You are caught up');

        $this->actingAs($admin)
            ->get(route('admin.work-queue.index', ['sort' => 'age', 'direction' => 'desc']))
            ->assertOk()
            ->assertSeeInOrder(['Old Alpha loan', 'Recent Beta loan']);

        $this->actingAs($admin)
            ->get(route('admin.work-queue.index', ['owner' => 'unassigned']))
            ->assertOk()
            ->assertSee('Old Alpha loan')
            ->assertDontSee('Recent Beta loan');
    }

    public function test_saved_views_store_only_validated_filters_and_are_revalidated_on_restore(): void
    {
        $this->bindSources([
            $this->source('loans', 'Loans', 'manage-loans', [
                $this->item('loans', 1, 'Urgent loan', now()->subDays(5), route('admin.loans.view', ['Loan' => 1])),
                $this->item('loans', 2, 'Routine loan', now()->subHours(2), route('admin.loans.view', ['Loan' => 2])),
            ]),
        ]);
        $admin = $this->createAdmin(['view-loans', 'manage-loans']);

        $response = $this->actingAs($admin)->post(route('admin.work-queue.saved-views.store'), [
            'name' => 'Urgent loans',
            'type' => 'loans',
            'urgency' => 'urgent',
            'sort' => 'age',
            'direction' => 'desc',
        ]);

        $response->assertRedirect();
        $savedView = StaffWorkQueueSavedView::query()->whereBelongsTo($admin)->sole();
        $this->assertSame('Urgent loans', $savedView->name);
        $this->assertSame(
            ['type' => 'loans', 'urgency' => 'urgent', 'sort' => 'age', 'direction' => 'desc'],
            $savedView->filters,
        );
        $this->assertArrayNotHasKey('subject', $savedView->filters);

        $this->actingAs($admin)
            ->get(route('admin.work-queue.index', ['saved_view' => $savedView->public_id]))
            ->assertOk()
            ->assertSee('Urgent loan')
            ->assertDontSee('Routine loan');

        $invalidView = StaffWorkQueueSavedView::query()->create([
            'user_id' => $admin->id,
            'public_id' => (string) Str::uuid(),
            'name' => 'Tampered view',
            'filters' => ['type' => 'applications'],
        ]);

        $this->actingAs($admin)
            ->get(route('admin.work-queue.index', ['saved_view' => $invalidView->public_id]))
            ->assertRedirect(route('admin.work-queue.index'))
            ->assertSessionHas('alert-type', 'warning');
        $this->assertModelExists($invalidView);

        $otherAdmin = $this->createAdmin(['view-loans', 'manage-loans']);
        $this->actingAs($otherAdmin)
            ->get(route('admin.work-queue.index', ['saved_view' => $savedView->public_id]))
            ->assertNotFound();
        $this->actingAs($otherAdmin)
            ->delete(route('admin.work-queue.saved-views.destroy', $savedView->public_id))
            ->assertNotFound();
        $this->assertModelExists($savedView);
    }

    public function test_failed_authorized_source_is_announced_without_hiding_healthy_work(): void
    {
        $this->bindSources([
            $this->source('loans', 'Loans', 'manage-loans', [
                $this->item('loans', 5, 'Healthy loan item', now()->subHour(), route('admin.loans.view', ['Loan' => 5])),
            ]),
            $this->source('applications', 'Applications', 'manage-applications', [], fail: true),
        ]);
        $admin = $this->createAdmin([
            'view-loans',
            'manage-loans',
            'view-applications',
            'manage-applications',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.work-queue.index'))
            ->assertOk()
            ->assertSee('Some types of work could not be loaded')
            ->assertSeeInOrder(['Applications', 'is temporarily unavailable'])
            ->assertSee('Healthy loan item')
            ->assertDontSee('You are caught up');
    }

    public function test_dashboard_and_navigation_counts_use_the_same_projection(): void
    {
        $this->bindSources([
            $this->source('loans', 'Loans', 'manage-loans', [
                $this->item('loans', 31, 'Projected dashboard loan', now()->subHour(), route('admin.loans.view', ['Loan' => 31])),
            ]),
        ]);
        $admin = $this->createAdmin(['view-loans', 'manage-loans']);

        $this->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('View all pending work')
            ->assertSee('1 pending')
            ->assertSee(route('admin.work-queue.index'), false)
            ->assertSee('Work queue');
    }

    public function test_model_changes_invalidate_the_projection_and_application_link_is_direct(): void
    {
        config()->set('pending_requests.permissions', [
            'applications' => 'manage-applications',
            'withdrawals' => 'manage-accounts',
            'city_grants' => 'manage-city-grants',
            'grants' => 'manage-grants',
            'loans' => 'manage-loans',
            'war_aid' => 'manage-war-aid',
            'rebuilding' => 'manage-rebuilding',
            'blockade_relief' => 'manage-war-room',
            'audit_remediation' => 'manage-audits',
        ]);
        $registry = app(StaffWorkQueueRegistry::class);
        $initial = $registry->snapshot(forceRefresh: true);
        $this->assertTrue($initial['complete'], json_encode($initial['failures'], JSON_THROW_ON_ERROR));
        $this->assertSame(0, $initial['counts']['applications']);
        $this->assertNotNull(Cache::get('testing.feature.staff-work-queue.projection'));
        Cache::put('testing.feature.staff-work-queue', ['applications' => 0], now()->addMinutes(10));

        $application = Application::query()->create([
            'nation_id' => 998877,
            'leader_name_snapshot' => 'Direct Link Leader',
            'discord_user_id' => 'discord-998877',
            'discord_username' => 'direct-link-user',
            'status' => ApplicationStatus::Pending,
        ]);

        $this->assertNull(Cache::get('testing.feature.staff-work-queue.projection'));
        $this->assertNull(Cache::get('testing.feature.staff-work-queue'));
        $refreshed = $registry->snapshot();
        $item = collect($refreshed['items'])->firstWhere('key', 'applications:'.$application->id);

        $this->assertSame(1, $refreshed['counts']['applications']);
        $this->assertSame(route('admin.applications.show', $application), $item['url']);
    }

    public function test_empty_projection_stays_within_the_provider_query_budget(): void
    {
        config()->set('pending_requests.permissions', [
            'applications' => 'manage-applications',
            'withdrawals' => 'manage-accounts',
            'city_grants' => 'manage-city-grants',
            'grants' => 'manage-grants',
            'loans' => 'manage-loans',
            'war_aid' => 'manage-war-aid',
            'rebuilding' => 'manage-rebuilding',
            'blockade_relief' => 'manage-war-room',
            'audit_remediation' => 'manage-audits',
        ]);
        $queries = 0;
        DB::listen(static function () use (&$queries): void {
            $queries++;
        });

        $snapshot = app(StaffWorkQueueRegistry::class)->snapshot(forceRefresh: true);

        $this->assertTrue($snapshot['complete'], json_encode($snapshot['failures'], JSON_THROW_ON_ERROR));
        $this->assertLessThanOrEqual(15, $queries);
    }

    /**
     * @param  list<StaffWorkQueueSource>  $sources
     */
    private function bindSources(array $sources): void
    {
        $this->app->instance(StaffWorkQueueRegistry::class, new StaffWorkQueueRegistry($sources));
        Cache::forget('testing.feature.staff-work-queue.projection');
    }

    /**
     * @param  list<StaffWorkItem>  $items
     */
    private function source(
        string $type,
        string $label,
        string $ability,
        array $items,
        bool $fail = false,
    ): StaffWorkQueueSource {
        return new FeatureStaffWorkQueueSource($type, $label, $ability, $items, $fail);
    }

    private function item(
        string $type,
        int $id,
        string $subject,
        \DateTimeInterface $createdAt,
        string $url,
        ?string $ownerKey = null,
        ?string $ownerLabel = null,
    ): StaffWorkItem {
        return new StaffWorkItem(
            type: $type,
            id: $id,
            typeLabel: str($type)->headline()->toString(),
            subject: $subject,
            createdAt: $createdAt,
            ownerKey: $ownerKey,
            ownerLabel: $ownerLabel,
            statusLabel: 'Pending review',
            statusIntent: 'pending',
            statusIcon: 'clock',
            nextActionLabel: 'Review item',
            url: $url,
        );
    }

    /**
     * @param  list<string>  $permissions
     */
    private function createAdmin(array $permissions): User
    {
        $admin = $this->createVerifiedAdmin([
            'nation_id' => fake()->unique()->numberBetween(700_000, 799_999),
        ]);
        $this->attachDiscordAccount($admin);

        return $this->grantPermissions($admin, $permissions);
    }
}

final class FeatureStaffWorkQueueSource implements StaffWorkQueueSource
{
    /**
     * @param  list<StaffWorkItem>  $items
     */
    public function __construct(
        private readonly string $type,
        private readonly string $label,
        private readonly string $ability,
        private readonly array $items,
        private readonly bool $fail = false,
    ) {}

    public function type(): string
    {
        return $this->type;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function ability(): string
    {
        return $this->ability;
    }

    public function load(): array
    {
        if ($this->fail) {
            throw new \RuntimeException('Simulated queue source failure.');
        }

        return $this->items;
    }
}
