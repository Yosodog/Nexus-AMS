<?php

namespace Tests\Feature\Admin;

use App\Domain\Milcom\Enums\AssignmentStatus;
use App\Domain\Milcom\Enums\ObjectiveStatus;
use App\Domain\Milcom\Enums\OperationStatus;
use App\Domain\Milcom\Enums\OperationType;
use App\Domain\Milcom\Enums\PriorityTier;
use App\Enums\ApplicationStatus;
use App\Enums\MemberTimelineCategory;
use App\Models\Account;
use App\Models\Application;
use App\Models\ApplicationMessage;
use App\Models\AuditResult;
use App\Models\AuditResultEvent;
use App\Models\AuditRule;
use App\Models\CityGrantRequest;
use App\Models\DiscordAccount;
use App\Models\GrantApplication;
use App\Models\Grants;
use App\Models\Loan;
use App\Models\MilcomAssignment;
use App\Models\MilcomAssignmentDelivery;
use App\Models\MilcomEvent;
use App\Models\MilcomObjective;
use App\Models\MilcomOperation;
use App\Models\Nation;
use App\Models\RecruitedNation;
use App\Models\RecruitmentMessage;
use App\Models\Role;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Admin\MemberTimeline\MemberTimelineService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\BuildsTestUsers;
use Tests\TestCase;

class MemberTimelineTest extends TestCase
{
    use BuildsTestUsers;
    use RefreshDatabase;

    public function test_profile_filters_are_url_persisted_and_an_empty_selection_is_understandable(): void
    {
        $viewer = $this->createAdmin(['view-members', 'view-applications']);
        $nation = $this->createMemberNation();
        $application = $this->createMembershipApplication($nation, now()->subDay());

        $this->actingAs($viewer)
            ->get(route('admin.members.show', [
                'Nation' => $nation->id,
                'timeline_filter' => 1,
                'timeline_categories' => ['applications'],
            ]))
            ->assertOk()
            ->assertSee('Member 360 timeline')
            ->assertSee('value="applications"', false)
            ->assertSee('checked', false)
            ->assertSee("data-timeline-source=\"application:{$application->id}:submitted\"", false)
            ->assertDontSee("data-timeline-source=\"nation:{$nation->id}:observed\"", false);

        $this->actingAs($viewer)
            ->get(route('admin.members.show', [
                'Nation' => $nation->id,
                'timeline_filter' => 1,
            ]))
            ->assertOk()
            ->assertSee('No retained activity matches this view.')
            ->assertSee('Older source records may also have expired under their retention policy.');
    }

    public function test_high_value_sources_have_timeline_parity_without_unsafe_payloads(): void
    {
        config()->set('milcom.v2_enabled', true);
        $viewer = $this->createAdmin($this->allTimelinePermissions());
        $fixture = $this->createFullFixture($viewer);
        $result = app(MemberTimelineService::class)->forNation($fixture['nation'], $viewer);
        $keys = $result->items->pluck('sourceKey')->all();

        $expected = [
            "nation:{$fixture['nation']->id}:observed",
            "user:{$fixture['member']->id}:created",
            "user:{$fixture['member']->id}:verified",
            "discord-account:{$fixture['discord_account']->id}:linked",
            "application:{$fixture['application']->id}:submitted",
            "application:{$fixture['application']->id}:decision:APPROVED",
            "transaction:{$fixture['transaction']->id}",
            "loan:{$fixture['loan']->id}",
            "grant-application:{$fixture['grant_application']->id}:submitted",
            "grant-application:{$fixture['grant_application']->id}:decision",
            "grant-application:{$fixture['grant_application']->id}:disbursed",
            "city-grant-request:{$fixture['city_grant']->id}",
            "audit-event:{$fixture['audit_event']->id}",
            "milcom-event:{$fixture['milcom_event']->id}",
            "milcom-event:{$fixture['delivery_event']->id}",
            "application-message:{$fixture['application_message']->id}",
            "recruitment:{$fixture['recruitment']->id}:primary-sent",
            "recruitment:{$fixture['recruitment']->id}:follow-up-sent",
        ];

        foreach ($expected as $sourceKey) {
            $this->assertContains($sourceKey, $keys, "Missing normalized source {$sourceKey}.");
        }

        $this->assertNotContains("milcom-assignment:{$fixture['assignment']->id}:current", $keys);
        $this->assertNotContains("milcom-delivery:{$fixture['delivery']->id}", $keys);
        $this->assertSame($result->items->count(), $result->items->pluck('deduplicationKey')->unique()->count());
        $this->assertFalse($result->isTruncated);
    }

    public function test_timeline_is_bounded_to_the_newest_thirty_records(): void
    {
        $viewer = $this->createAdmin(['view-members', 'view-applications']);
        $nation = $this->createMemberNation();
        $applications = collect(range(1, 35))->map(fn (int $offset): Application => $this->createMembershipApplication(
            $nation,
            CarbonImmutable::parse('2026-07-01 00:00:00')->addMinutes($offset),
        ));

        $result = app(MemberTimelineService::class)->forNation(
            $nation,
            $viewer,
            [MemberTimelineCategory::Applications],
        );

        $this->assertCount(MemberTimelineService::DISPLAY_LIMIT, $result->items);
        $this->assertTrue($result->isTruncated);
        $this->assertSame(
            'application:'.$applications->last()->id.':submitted',
            $result->items->first()->sourceKey,
        );
        $this->assertNotContains(
            'application:'.$applications->first()->id.':submitted',
            $result->items->pluck('sourceKey')->all(),
        );
    }

    public function test_permission_matrix_scopes_categories_and_source_queries_independently(): void
    {
        config()->set('milcom.v2_enabled', true);
        $fixtureOwner = $this->createAdmin($this->allTimelinePermissions());
        $fixture = $this->createFullFixture($fixtureOwner);
        $service = app(MemberTimelineService::class);

        $membersViewer = $this->createAdmin(['view-members']);
        [$membersResult, $memberQueries] = $this->captureQueries(
            $membersViewer,
            fn () => $service->forNation($fixture['nation'], $membersViewer),
        );
        $this->assertSame(['membership'], $this->categoryValues($membersResult->availableCategories));
        foreach (['applications', 'transactions', 'loans', 'grant_applications', 'audit_result_events', 'milcom_assignments', 'recruited_nations'] as $table) {
            $this->assertSame(0, $this->queryCountForTable($memberQueries, $table), "Unauthorized query against {$table}.");
        }

        $applicationsViewer = $this->createAdmin(['view-members', 'view-applications']);
        [$applicationCommunications, $applicationQueries] = $this->captureQueries(
            $applicationsViewer,
            fn () => $service->forNation($fixture['nation'], $applicationsViewer, [MemberTimelineCategory::Communications]),
        );
        $this->assertContains("application-message:{$fixture['application_message']->id}", $applicationCommunications->items->pluck('sourceKey')->all());
        $this->assertGreaterThan(0, $this->queryCountForTable($applicationQueries, 'application_messages'));
        $this->assertSame(0, $this->queryCountForTable($applicationQueries, 'recruited_nations'));

        $recruitmentViewer = $this->createAdmin(['view-members', 'view-recruitment']);
        [$recruitmentCommunications, $recruitmentQueries] = $this->captureQueries(
            $recruitmentViewer,
            fn () => $service->forNation($fixture['nation'], $recruitmentViewer, [MemberTimelineCategory::Communications]),
        );
        $this->assertContains("recruitment:{$fixture['recruitment']->id}:primary-sent", $recruitmentCommunications->items->pluck('sourceKey')->all());
        $this->assertGreaterThan(0, $this->queryCountForTable($recruitmentQueries, 'recruited_nations'));
        $this->assertSame(0, $this->queryCountForTable($recruitmentQueries, 'application_messages'));

        $loanViewer = $this->createAdmin(['view-members', 'view-loans']);
        [$loanResult, $loanQueries] = $this->captureQueries(
            $loanViewer,
            fn () => $service->forNation($fixture['nation'], $loanViewer, [MemberTimelineCategory::Finance]),
        );
        $this->assertContains("loan:{$fixture['loan']->id}", $loanResult->items->pluck('sourceKey')->all());
        $this->assertGreaterThan(0, $this->queryCountForTable($loanQueries, 'loans'));
        $this->assertSame(0, $this->queryCountForTable($loanQueries, 'transactions'));
        $this->assertSame(0, $this->queryCountForTable($loanQueries, 'grant_applications'));

        $auditViewer = $this->createAdmin(['view-members', 'view-audits']);
        $auditResult = $service->forNation($fixture['nation'], $auditViewer, [MemberTimelineCategory::Audits]);
        $this->assertContains("audit-event:{$fixture['audit_event']->id}", $auditResult->items->pluck('sourceKey')->all());

        $militaryViewer = $this->createAdmin(['view-members', 'manage-war-room']);
        $militaryResult = $service->forNation($fixture['nation'], $militaryViewer, [MemberTimelineCategory::Military]);
        $this->assertContains("milcom-event:{$fixture['milcom_event']->id}", $militaryResult->items->pluck('sourceKey')->all());
    }

    public function test_sensitive_columns_and_message_bodies_are_never_selected_or_rendered(): void
    {
        config()->set('milcom.v2_enabled', true);
        $viewer = $this->createAdmin($this->allTimelinePermissions());
        $fixture = $this->createFullFixture($viewer);
        [, $queries] = $this->captureQueries(
            $viewer,
            fn () => app(MemberTimelineService::class)->forNation($fixture['nation'], $viewer),
        );
        $sql = implode("\n", $queries);

        foreach ([
            'decision_internal_note',
            'bank_reconciliation_details',
            'offshore_fulfillment_details',
            'payload_snapshot',
            'last_error',
            'application_messages"."content',
            'audit_result_events"."metadata',
            'audit_results"."details',
            'milcom_events"."payload',
            'users"."email',
            'users"."password',
            'discord_accounts"."discord_id',
            '"audit_logs"',
            '"recruitment_messages"',
        ] as $forbiddenColumn) {
            $this->assertStringNotContainsString($forbiddenColumn, $sql);
        }

        $response = $this->actingAs($viewer)
            ->get(route('admin.members.show', ['Nation' => $fixture['nation']->id]))
            ->assertOk();

        foreach ($fixture['secrets'] as $secret) {
            $response->assertDontSee($secret);
        }
    }

    public function test_overlapping_milcom_events_win_deduplication_deterministically(): void
    {
        config()->set('milcom.v2_enabled', true);
        $viewer = $this->createAdmin(['view-members', 'manage-war-room']);
        $fixture = $this->createFullFixture($viewer);
        $result = app(MemberTimelineService::class)->forNation(
            $fixture['nation'],
            $viewer,
            [MemberTimelineCategory::Military],
        );
        $assignmentKey = "milcom-assignment:{$fixture['assignment']->id}:engaged";
        $deliveryKey = "milcom-delivery:{$fixture['assignment']->id}:in_game:sent";

        $this->assertCount(1, $result->items->where('deduplicationKey', $assignmentKey));
        $this->assertSame(
            "milcom-event:{$fixture['milcom_event']->id}",
            $result->items->firstWhere('deduplicationKey', $assignmentKey)->sourceKey,
        );
        $this->assertCount(1, $result->items->where('deduplicationKey', $deliveryKey));
        $this->assertSame(
            "milcom-event:{$fixture['delivery_event']->id}",
            $result->items->firstWhere('deduplicationKey', $deliveryKey)->sourceKey,
        );
    }

    public function test_items_are_chronological_with_a_stable_source_key_tie_breaker(): void
    {
        $viewer = $this->createAdmin(['view-members', 'view-applications']);
        $nation = $this->createMemberNation();
        $submittedAt = CarbonImmutable::parse('2026-07-01 10:00:00');
        $application = $this->createMembershipApplication($nation, $submittedAt, ApplicationStatus::Approved, $submittedAt->addHours(2));
        $message = ApplicationMessage::query()->create([
            'application_id' => $application->id,
            'discord_message_id' => 'timeline-order-message',
            'discord_user_id' => 'timeline-order-user',
            'discord_username' => 'Timeline Applicant',
            'discord_channel_id' => 'timeline-order-channel',
            'content' => 'Body is intentionally not projected.',
            'is_staff' => false,
            'sent_at' => $submittedAt->addHour(),
        ]);

        $result = app(MemberTimelineService::class)->forNation(
            $nation,
            $viewer,
            [MemberTimelineCategory::Applications, MemberTimelineCategory::Communications],
        );

        $this->assertSame([
            "application:{$application->id}:decision:APPROVED",
            "application-message:{$message->id}",
            "application:{$application->id}:submitted",
        ], $result->items->pluck('sourceKey')->all());

        $sameTimeApplication = $this->createMembershipApplication($nation, $submittedAt->addDays(2));
        $sameTimeApplicationTwo = $this->createMembershipApplication($nation, $submittedAt->addDays(2));
        $tieResult = app(MemberTimelineService::class)->forNation(
            $nation,
            $viewer,
            [MemberTimelineCategory::Applications],
        );
        $tieKeys = [
            "application:{$sameTimeApplication->id}:submitted",
            "application:{$sameTimeApplicationTwo->id}:submitted",
        ];
        sort($tieKeys);

        $this->assertSame($tieKeys, $tieResult->items->pluck('sourceKey')->take(2)->all());
    }

    public function test_full_projection_stays_within_a_fixed_query_budget(): void
    {
        config()->set('milcom.v2_enabled', true);
        $viewer = $this->createAdmin($this->allTimelinePermissions());
        $fixture = $this->createFullFixture($viewer);
        [$result, $queries] = $this->captureQueries(
            $viewer,
            fn () => app(MemberTimelineService::class)->forNation($fixture['nation'], $viewer),
        );

        $this->assertNotEmpty($result->items);
        $this->assertLessThanOrEqual(25, count($queries), 'The member timeline projection exceeded its fixed query budget.');

        foreach ($queries as $query) {
            if ($this->isTimelineStreamQuery($query)) {
                $this->assertStringContainsString('limit 31', $query);
            }
        }
    }

    public function test_direct_links_are_only_emitted_and_openable_with_domain_permissions(): void
    {
        $owner = $this->createAdmin($this->allTimelinePermissions());
        $fixture = $this->createFullFixture($owner);
        $membersViewer = $this->createAdmin(['view-members']);
        $applicationUrl = route('admin.applications.show', ['application' => $fixture['application']->id]);
        $accountUrl = route('admin.accounts.view', ['accounts' => $fixture['account']->id]);
        $membersResult = app(MemberTimelineService::class)->forNation($fixture['nation'], $membersViewer);

        $this->assertNotContains($applicationUrl, $membersResult->items->pluck('sourceUrl')->filter()->all());
        $this->assertNotContains($accountUrl, $membersResult->items->pluck('sourceUrl')->filter()->all());
        $this->actingAs($membersViewer)->get($applicationUrl)->assertForbidden();
        $this->actingAs($membersViewer)->get($accountUrl)->assertForbidden();

        $domainViewer = $this->createAdmin(['view-members', 'view-applications', 'view-accounts']);
        $domainResult = app(MemberTimelineService::class)->forNation($fixture['nation'], $domainViewer);
        $links = $domainResult->items->pluck('sourceUrl')->filter()->all();

        $this->assertContains($applicationUrl, $links);
        $this->assertContains($accountUrl, $links);
        $this->actingAs($domainViewer)->get($applicationUrl)->assertOk();
        $this->actingAs($domainViewer)->get($accountUrl)->assertOk();
    }

    public function test_account_lifecycle_is_hidden_when_role_delegation_blocks_the_user_record(): void
    {
        $viewer = $this->createAdmin(['view-members', 'edit-users']);
        $nation = $this->createMemberNation();
        $target = $this->createVerifiedUser(['nation_id' => $nation->id]);
        $protectedRole = Role::query()->create([
            'name' => 'Protected timeline target',
            'protected' => true,
        ]);
        $target->roles()->attach($protectedRole);
        $this->attachDiscordAccount($target, ['discord_id' => 'hidden-timeline-discord-id']);

        $result = app(MemberTimelineService::class)->forNation(
            $nation,
            $viewer,
            [MemberTimelineCategory::Membership],
        );
        $keys = $result->items->pluck('sourceKey')->all();

        $this->assertContains("nation:{$nation->id}:observed", $keys);
        $this->assertFalse(collect($keys)->contains(fn (string $key): bool => str_starts_with($key, 'user:')));
        $this->assertFalse(collect($keys)->contains(fn (string $key): bool => str_starts_with($key, 'discord-account:')));
        $this->assertNotContains(
            route('admin.users.edit', ['user' => $target->id]),
            $result->items->pluck('sourceUrl')->filter()->all(),
        );
    }

    public function test_invalid_timeline_category_is_rejected_without_querying_a_source(): void
    {
        $viewer = $this->createAdmin(['view-members']);
        $nation = $this->createMemberNation();

        $this->actingAs($viewer)
            ->from(route('admin.members.show', ['Nation' => $nation->id]))
            ->get(route('admin.members.show', [
                'Nation' => $nation->id,
                'timeline_filter' => 1,
                'timeline_categories' => ['security-signals'],
            ]))
            ->assertRedirect(route('admin.members.show', ['Nation' => $nation->id]))
            ->assertSessionHasErrors('timeline_categories.0');
    }

    public function test_unavailable_permitted_source_yields_an_inline_partial_state(): void
    {
        $viewer = $this->createAdmin(['view-members', 'view-audits']);
        $nation = $this->createMemberNation();
        Schema::rename('audit_result_events', 'audit_result_events_unavailable');

        try {
            $this->actingAs($viewer)
                ->get(route('admin.members.show', [
                    'Nation' => $nation->id,
                    'timeline_filter' => 1,
                    'timeline_categories' => ['audits'],
                ]))
                ->assertOk()
                ->assertSee('Some activity is temporarily unavailable.')
                ->assertSee('Audits could not be loaded.')
                ->assertSee('Other retained sources are shown below.');
        } finally {
            Schema::rename('audit_result_events_unavailable', 'audit_result_events');
        }
    }

    /**
     * @return array{
     *     nation: Nation,
     *     member: User,
     *     discord_account: DiscordAccount,
     *     application: Application,
     *     application_message: ApplicationMessage,
     *     account: Account,
     *     transaction: Transaction,
     *     loan: Loan,
     *     grant_application: GrantApplication,
     *     city_grant: CityGrantRequest,
     *     audit_event: AuditResultEvent,
     *     assignment: MilcomAssignment,
     *     milcom_event: MilcomEvent,
     *     delivery: MilcomAssignmentDelivery,
     *     delivery_event: MilcomEvent,
     *     recruitment: RecruitedNation,
     *     secrets: list<string>
     * }
     */
    private function createFullFixture(User $staffActor): array
    {
        $base = CarbonImmutable::parse('2026-07-15 12:00:00');
        $nation = $this->createMemberNation(['created_at' => $base->subDays(30), 'updated_at' => $base]);
        $member = $this->createVerifiedUser(['nation_id' => $nation->id, 'name' => 'Timeline Member']);
        DB::table('users')->where('id', $member->id)->update([
            'created_at' => $base->subDays(20),
            'updated_at' => $base->subDays(18),
            'verified_at' => $base->subDays(18),
        ]);
        $member = $member->fresh();
        $discordAccount = $this->attachDiscordAccount($member, [
            'discord_id' => 'timeline-discord-secret-id',
            'discord_username' => 'Timeline Discord',
            'linked_at' => $base->subDays(17),
        ]);
        $application = $this->createMembershipApplication(
            $nation,
            $base->subDays(16),
            ApplicationStatus::Approved,
            $base->subDays(15),
        );
        $applicationMessage = ApplicationMessage::query()->create([
            'application_id' => $application->id,
            'discord_message_id' => 'timeline-message-id',
            'discord_user_id' => 'timeline-message-user-id',
            'discord_username' => 'Timeline Applicant',
            'discord_channel_id' => 'timeline-message-channel-id',
            'content' => 'SECRET-APPLICATION-MESSAGE-BODY',
            'is_staff' => false,
            'sent_at' => $base->subDays(15)->addHour(),
        ]);
        $account = new Account;
        $account->forceFill([
            'nation_id' => $nation->id,
            'name' => 'Timeline account',
            'money' => 50_000,
        ])->save();
        $transaction = new Transaction;
        $transaction->forceFill([
            'nation_id' => $nation->id,
            'to_account_id' => $account->id,
            'transaction_type' => 'deposit',
            'money' => 12_345.67,
            'is_pending' => false,
            'sent_at' => $base->subDays(12),
            'bank_reconciliation_details' => ['secret' => 'SECRET-BANK-RECONCILIATION'],
            'offshore_fulfillment_details' => ['secret' => 'SECRET-OFFSHORE-DETAIL'],
            'created_at' => $base->subDays(13),
            'updated_at' => $base->subDays(12),
        ])->save();
        $loan = Loan::query()->create([
            'nation_id' => $nation->id,
            'account_id' => $account->id,
            'amount' => 25_000,
            'remaining_balance' => 25_000,
            'interest_rate' => 2,
            'term_weeks' => 10,
            'status' => 'approved',
            'approved_at' => $base->subDays(11),
        ]);
        $grant = new Grants;
        $grant->forceFill([
            'name' => 'Timeline grant',
            'slug' => 'timeline-grant',
            'description' => 'Timeline fixture grant.',
            'is_enabled' => true,
            'is_one_time' => true,
            'version' => 3,
        ])->save();
        $grantApplication = GrantApplication::query()->create([
            'grant_id' => $grant->id,
            'program_name_snapshot' => 'Timeline grant',
            'program_version_snapshot' => 3,
            'nation_id' => $nation->id,
            'account_id' => $account->id,
            'status' => 'approved',
            'decision_internal_note' => 'SECRET-GRANT-INTERNAL-NOTE',
            'reviewed_by_user_id' => $staffActor->id,
            'submitted_at' => $base->subDays(10),
            'approved_at' => $base->subDays(9),
            'decided_at' => $base->subDays(9),
            'disbursed_at' => $base->subDays(8),
            'money' => 5_000,
        ]);
        $cityGrant = CityGrantRequest::query()->create([
            'city_number' => 20,
            'grant_amount' => 2_000_000,
            'nation_id' => $nation->id,
            'account_id' => $account->id,
            'status' => 'approved',
            'approved_at' => $base->subDays(7),
        ]);
        $auditRule = new AuditRule;
        $auditRule->forceFill([
            'name' => 'SECRET-FRAUD-SIGNAL-RULE-NAME',
            'description' => 'Sensitive audit fixture.',
            'target_type' => 'nation',
            'priority' => 'high',
            'definition' => null,
            'enabled' => false,
            'created_by' => $staffActor->id,
            'updated_by' => $staffActor->id,
        ])->save();
        $auditResult = AuditResult::query()->create([
            'audit_rule_id' => $auditRule->id,
            'rule_revision' => 1,
            'target_type' => 'nation',
            'target_key' => "nation:{$nation->id}",
            'nation_id' => $nation->id,
            'details' => ['secret' => 'SECRET-AUDIT-EVIDENCE'],
            'first_detected_at' => $base->subDays(6),
            'last_evaluated_at' => $base->subDays(6),
            'remediation_note' => 'SECRET-AUDIT-PRIVATE-NOTE',
        ]);
        $auditEvent = AuditResultEvent::query()->create([
            'audit_result_id' => $auditResult->id,
            'audit_rule_id' => $auditRule->id,
            'target_type' => 'nation',
            'target_key' => "nation:{$nation->id}",
            'nation_id' => $nation->id,
            'actor_user_id' => $staffActor->id,
            'event_type' => 'acknowledged',
            'metadata' => ['secret' => 'SECRET-AUDIT-EVENT-METADATA'],
            'occurred_at' => $base->subDays(5),
        ]);
        $targetNation = Nation::factory()->create();
        $operation = new MilcomOperation;
        $operation->forceFill([
            'type' => OperationType::Plan,
            'status' => OperationStatus::Active,
            'current_stage' => 'active',
            'name' => 'SECRET-MILCOM-OPERATION-NAME',
            'created_by' => $staffActor->id,
            'metadata' => ['secret' => 'SECRET-MILCOM-OPERATION-METADATA'],
        ])->save();
        $objective = new MilcomObjective;
        $objective->forceFill([
            'operation_id' => $operation->id,
            'target_nation_id' => $targetNation->id,
            'priority_tier' => PriorityTier::Standard,
            'priority_score' => 50,
            'status' => ObjectiveStatus::Engaged,
            'open_key' => 1,
            'metadata' => ['secret' => 'SECRET-MILCOM-OBJECTIVE-METADATA'],
        ])->save();
        $assignment = new MilcomAssignment;
        $assignment->forceFill([
            'objective_id' => $objective->id,
            'friendly_nation_id' => $nation->id,
            'score' => 88,
            'confidence' => 95,
            'status' => AssignmentStatus::Engaged,
            'engaged_at' => $base->subDays(4),
            'factor_explanations' => ['secret' => 'SECRET-MILCOM-FACTOR'],
        ])->save();
        $milcomEvent = MilcomEvent::query()->create([
            'operation_id' => $operation->id,
            'objective_id' => $objective->id,
            'assignment_id' => $assignment->id,
            'actor_user_id' => $staffActor->id,
            'source' => 'staff',
            'event_type' => 'assignment.engaged',
            'payload' => ['secret' => 'SECRET-MILCOM-EVENT-PAYLOAD'],
            'occurred_at' => $base->subDays(4),
        ]);
        $delivery = MilcomAssignmentDelivery::query()->create([
            'operation_id' => $operation->id,
            'assignment_id' => $assignment->id,
            'channel' => 'in_game',
            'status' => 'sent',
            'dedupe_key' => "timeline-delivery-{$assignment->id}",
            'subject' => 'SECRET-MILCOM-DELIVERY-SUBJECT',
            'payload_snapshot' => ['secret' => 'SECRET-MILCOM-DELIVERY-PAYLOAD'],
            'attempts' => 1,
            'sent_at' => $base->subDays(3),
        ]);
        $deliveryEvent = MilcomEvent::query()->create([
            'operation_id' => $operation->id,
            'objective_id' => $objective->id,
            'assignment_id' => $assignment->id,
            'source' => 'system',
            'event_type' => 'assignment.in_game_sent',
            'payload' => ['secret' => 'SECRET-MILCOM-DELIVERY-EVENT'],
            'occurred_at' => $base->subDays(3),
        ]);
        $recruitment = RecruitedNation::query()->create([
            'nation_id' => $nation->id,
            'primary_sent_at' => $base->subDays(25),
            'follow_up_scheduled_for' => $base->subDays(23),
            'follow_up_sent_at' => $base->subDays(22),
        ]);
        RecruitmentMessage::query()->where('type', 'primary')->update([
            'message' => 'SECRET-RECRUITMENT-MESSAGE-BODY',
        ]);

        return [
            'nation' => $nation,
            'member' => $member,
            'discord_account' => $discordAccount,
            'application' => $application,
            'application_message' => $applicationMessage,
            'account' => $account,
            'transaction' => $transaction,
            'loan' => $loan,
            'grant_application' => $grantApplication,
            'city_grant' => $cityGrant,
            'audit_event' => $auditEvent,
            'assignment' => $assignment,
            'milcom_event' => $milcomEvent,
            'delivery' => $delivery,
            'delivery_event' => $deliveryEvent,
            'recruitment' => $recruitment,
            'secrets' => [
                'SECRET-APPLICATION-MESSAGE-BODY',
                'SECRET-BANK-RECONCILIATION',
                'SECRET-OFFSHORE-DETAIL',
                'SECRET-GRANT-INTERNAL-NOTE',
                'SECRET-FRAUD-SIGNAL-RULE-NAME',
                'SECRET-AUDIT-EVIDENCE',
                'SECRET-AUDIT-PRIVATE-NOTE',
                'SECRET-AUDIT-EVENT-METADATA',
                'SECRET-MILCOM-OPERATION-NAME',
                'SECRET-MILCOM-OPERATION-METADATA',
                'SECRET-MILCOM-OBJECTIVE-METADATA',
                'SECRET-MILCOM-FACTOR',
                'SECRET-MILCOM-EVENT-PAYLOAD',
                'SECRET-MILCOM-DELIVERY-SUBJECT',
                'SECRET-MILCOM-DELIVERY-PAYLOAD',
                'SECRET-MILCOM-DELIVERY-EVENT',
                'SECRET-RECRUITMENT-MESSAGE-BODY',
                'timeline-discord-secret-id',
            ],
        ];
    }

    private function createMembershipApplication(
        Nation $nation,
        \DateTimeInterface $createdAt,
        ApplicationStatus $status = ApplicationStatus::Pending,
        ?\DateTimeInterface $decisionAt = null,
    ): Application {
        return Application::query()->create([
            'nation_id' => $nation->id,
            'leader_name_snapshot' => $nation->leader_name,
            'discord_user_id' => 'timeline-applicant-'.$createdAt->getTimestamp().'-'.fake()->unique()->numberBetween(1, 999_999),
            'discord_username' => 'Timeline Applicant',
            'discord_channel_id' => 'timeline-channel-'.$createdAt->getTimestamp().'-'.fake()->unique()->numberBetween(1, 999_999),
            'status' => $status,
            'approved_at' => $status === ApplicationStatus::Approved ? $decisionAt : null,
            'denied_at' => $status === ApplicationStatus::Denied ? $decisionAt : null,
            'cancelled_at' => $status === ApplicationStatus::Cancelled ? $decisionAt : null,
            'created_at' => $createdAt,
            'updated_at' => $decisionAt ?? $createdAt,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function createMemberNation(array $attributes = []): Nation
    {
        return Nation::factory()->create([
            'alliance_id' => 777,
            'alliance_position' => 'MEMBER',
            ...$attributes,
        ]);
    }

    /** @param list<string> $permissions */
    private function createAdmin(array $permissions): User
    {
        $admin = $this->createVerifiedAdmin([
            'nation_id' => fake()->unique()->numberBetween(800_000, 999_999),
        ]);
        $this->attachDiscordAccount($admin);

        return $this->grantPermissions($admin, $permissions);
    }

    /** @return list<string> */
    private function allTimelinePermissions(): array
    {
        return [
            'view-members',
            'edit-users',
            'view-applications',
            'view-recruitment',
            'view-accounts',
            'view-loans',
            'view-grants',
            'view-city-grants',
            'view-audits',
            'manage-war-room',
        ];
    }

    /**
     * @template TResult
     *
     * @param  callable(): TResult  $callback
     * @return array{TResult, list<string>}
     */
    private function captureQueries(User $viewer, callable $callback): array
    {
        $viewer->load('roles.permissions');
        $connection = DB::connection();
        $connection->flushQueryLog();
        $connection->enableQueryLog();
        $result = $callback();
        $queries = collect($connection->getQueryLog())
            ->pluck('query')
            ->map(fn (string $query): string => strtolower($query))
            ->values()
            ->all();
        $connection->disableQueryLog();

        return [$result, $queries];
    }

    /** @param list<string> $queries */
    private function queryCountForTable(array $queries, string $table): int
    {
        return collect($queries)
            ->filter(fn (string $query): bool => str_contains($query, '"'.$table.'"') || str_contains($query, '`'.$table.'`'))
            ->count();
    }

    /** @param list<MemberTimelineCategory> $categories @return list<string> */
    private function categoryValues(array $categories): array
    {
        return collect($categories)->map(fn (MemberTimelineCategory $category): string => $category->value)->all();
    }

    private function isTimelineStreamQuery(string $query): bool
    {
        foreach ([
            '"applications"',
            '"application_messages"',
            '"transactions"',
            '"loans"',
            '"grant_applications"',
            '"city_grant_requests"',
            '"audit_result_events"',
            '"audit_results"',
            '"milcom_assignments"',
            '"milcom_events"',
            '"milcom_assignment_deliveries"',
            '"recruited_nations"',
            '"discord_accounts"',
        ] as $table) {
            if (str_contains($query, $table)) {
                return true;
            }
        }

        return false;
    }
}
