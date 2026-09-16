<?php

namespace Tests\Feature\Admin;

use App\GraphQL\Models\Nation;
use App\Jobs\SendRecruitmentMessage;
use App\Models\RecruitedNation;
use App\Models\RecruitmentMessage;
use App\Models\RecruitmentMessageClick;
use App\Models\User;
use App\Services\PWMessageService;
use App\Services\RecruitmentService;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\Concerns\BuildsTestUsers;
use Tests\TestCase;

class RecruitmentABTestingTest extends TestCase
{
    use BuildsTestUsers;
    use RefreshDatabase;

    public function test_admin_can_view_recruitment_dashboard_and_variants(): void
    {
        $admin = $this->createAdmin(['view-recruitment']);

        $variant = RecruitmentMessage::factory()->create([
            'name' => 'High Growth Pitch',
            'subject' => 'Join the High Growth Alliance',
            'lifetime_sends' => 100,
            'lifetime_clicks' => 20,
            'current_sends' => 50,
            'current_clicks' => 10,
        ]);

        $response = $this->actingAs($admin)
            ->get(route('admin.recruitment.index'))
            ->assertOk()
            ->assertSee('Recruitment Messaging &amp; A/B Testing', false)
            ->assertSee('High Growth Pitch')
            ->assertSee('Join the High Growth Alliance')
            ->assertSee('Reset Current Test');

        $this->assertStringContainsString($variant->tracking_url, $response->getContent());
    }

    public function test_adding_new_message_resets_current_cohort_metrics_while_preserving_lifetime_metrics(): void
    {
        $admin = $this->createAdmin(['view-recruitment', 'manage-recruitment']);

        $existing = RecruitmentMessage::factory()->create([
            'name' => 'Original Variant',
            'subject' => 'Original Subject',
            'lifetime_sends' => 150,
            'lifetime_clicks' => 30,
            'current_sends' => 60,
            'current_clicks' => 12,
        ]);

        $response = $this->actingAs($admin)
            ->post(route('admin.recruitment.messages.store'), [
                'name' => 'New Challenger Variant',
                'subject' => 'Fresh Recruitment Offer',
                'message' => '<p>Check out our perks at {apply_link}!</p>',
                'is_active' => '1',
            ]);

        $response->assertRedirect(route('admin.recruitment.index'))
            ->assertSessionHas('alert-type', 'success');

        $existing->refresh();
        $this->assertSame(150, $existing->lifetime_sends);
        $this->assertSame(30, $existing->lifetime_clicks);
        $this->assertSame(0, $existing->current_sends);
        $this->assertSame(0, $existing->current_clicks);

        $new = RecruitmentMessage::where('name', 'New Challenger Variant')->firstOrFail();
        $this->assertSame('Fresh Recruitment Offer', $new->subject);
        $this->assertTrue($new->is_active);
        $this->assertSame(0, $new->current_sends);
        $this->assertSame(0, $new->current_clicks);
        $this->assertSame(0, $new->lifetime_sends);
        $this->assertSame(0, $new->lifetime_clicks);
        $this->assertNotEmpty($new->tracking_key);
        $this->assertNotNull(SettingService::getRecruitmentCurrentCohortStartedAt());
        $this->assertNotNull(SettingService::getRecruitmentCurrentCohortKey());
    }

    public function test_admin_can_create_an_inactive_variant(): void
    {
        $admin = $this->createAdmin(['view-recruitment', 'manage-recruitment']);

        $this->actingAs($admin)
            ->post(route('admin.recruitment.messages.store'), [
                'name' => 'Paused Challenger',
                'subject' => 'Paused Subject',
                'message' => '<p>Paused body</p>',
            ])
            ->assertRedirect(route('admin.recruitment.index'));

        $this->assertFalse(
            RecruitmentMessage::where('name', 'Paused Challenger')->firstOrFail()->is_active
        );
    }

    public function test_admin_can_update_variant(): void
    {
        $admin = $this->createAdmin(['view-recruitment', 'manage-recruitment']);

        $variant = RecruitmentMessage::factory()->create([
            'name' => 'Old Title',
            'subject' => 'Old Subject',
            'message' => '<p>Old body</p>',
        ]);

        $response = $this->actingAs($admin)
            ->put(route('admin.recruitment.messages.update', $variant), [
                'name' => 'Updated Title',
                'subject' => 'Updated Subject',
                'message' => '<p>New body with {apply_link}</p>',
                'is_active' => '1',
            ]);

        $response->assertRedirect(route('admin.recruitment.index'))
            ->assertSessionHas('alert-type', 'success');

        $variant->refresh();
        $this->assertSame('Updated Title', $variant->name);
        $this->assertSame('Updated Subject', $variant->subject);
        $this->assertSame('<p>New body with {apply_link}</p>', $variant->message);
    }

    public function test_admin_can_deactivate_variant_from_edit_form(): void
    {
        $admin = $this->createAdmin(['view-recruitment', 'manage-recruitment']);
        $variant = RecruitmentMessage::factory()->create(['is_active' => true]);

        $this->actingAs($admin)
            ->put(route('admin.recruitment.messages.update', $variant), [
                'name' => 'Paused Variant',
                'subject' => 'Paused Subject',
                'message' => '<p>Paused body</p>',
            ])
            ->assertRedirect(route('admin.recruitment.index'));

        $this->assertFalse($variant->fresh()->is_active);
    }

    public function test_admin_can_toggle_variant_active_status(): void
    {
        $admin = $this->createAdmin(['view-recruitment', 'manage-recruitment']);

        $variant = RecruitmentMessage::factory()->create(['is_active' => true]);

        $this->actingAs($admin)
            ->post(route('admin.recruitment.messages.toggle', $variant))
            ->assertRedirect(route('admin.recruitment.index'));

        $this->assertFalse($variant->fresh()->is_active);

        $this->actingAs($admin)
            ->post(route('admin.recruitment.messages.toggle', $variant))
            ->assertRedirect(route('admin.recruitment.index'));

        $this->assertTrue($variant->fresh()->is_active);
    }

    public function test_admin_can_delete_variant(): void
    {
        $admin = $this->createAdmin(['view-recruitment', 'manage-recruitment']);

        $variant = RecruitmentMessage::factory()->create();

        $this->actingAs($admin)
            ->delete(route('admin.recruitment.messages.destroy', $variant))
            ->assertRedirect(route('admin.recruitment.index'));

        $this->assertSoftDeleted('recruitment_messages', ['id' => $variant->id]);
    }

    public function test_admin_can_manually_reset_cohort_metrics(): void
    {
        $admin = $this->createAdmin(['view-recruitment', 'manage-recruitment']);

        $variant = RecruitmentMessage::factory()->create([
            'current_sends' => 45,
            'current_clicks' => 9,
            'lifetime_sends' => 200,
            'lifetime_clicks' => 50,
        ]);

        $this->actingAs($admin)
            ->post(route('admin.recruitment.reset-cohort'))
            ->assertRedirect(route('admin.recruitment.index'))
            ->assertSessionHas('alert-type', 'success');

        $variant->refresh();
        $this->assertSame(0, $variant->current_sends);
        $this->assertSame(0, $variant->current_clicks);
        $this->assertSame(200, $variant->lifetime_sends);
        $this->assertSame(50, $variant->lifetime_clicks);
    }

    public function test_tracking_link_records_clicks_and_redirects_to_apply(): void
    {
        $variant = RecruitmentMessage::factory()->create([
            'tracking_key' => 'track123abc',
            'lifetime_clicks' => 5,
            'current_clicks' => 2,
        ]);

        $trackingUrl = $variant->tracking_url;
        $response = $this->get($trackingUrl);

        $response->assertRedirect(route('apply.show', [
            'utm_source' => 'recruitment',
            'utm_campaign' => 'track123abc',
            'ref' => 'recruitment',
        ]));

        $variant->refresh();
        $this->assertSame(6, $variant->lifetime_clicks);
        $this->assertSame(3, $variant->current_clicks);

        $this->assertDatabaseHas('recruitment_message_clicks', [
            'recruitment_message_id' => $variant->id,
        ]);
    }

    public function test_tracking_link_deduplicates_repeated_clicks_in_same_session(): void
    {
        $variant = RecruitmentMessage::factory()->create([
            'tracking_key' => 'dedup789xyz',
            'lifetime_clicks' => 0,
            'current_clicks' => 0,
        ]);

        $trackingUrl = $variant->tracking_url;

        $this->get($trackingUrl)
            ->assertRedirect();

        $this->assertSame(1, $variant->fresh()->lifetime_clicks);
        $this->assertSame(1, $variant->fresh()->current_clicks);
        $this->assertSame(1, RecruitmentMessageClick::where('recruitment_message_id', $variant->id)->count());

        // Repeat click in same session
        $this->get($trackingUrl)
            ->assertRedirect();

        $this->assertSame(1, $variant->fresh()->lifetime_clicks);
        $this->assertSame(1, $variant->fresh()->current_clicks);
        $this->assertSame(1, RecruitmentMessageClick::where('recruitment_message_id', $variant->id)->count());
    }

    public function test_direct_apply_page_with_rm_parameter_records_click(): void
    {
        $variant = RecruitmentMessage::factory()->create([
            'tracking_key' => 'directrm456',
            'lifetime_clicks' => 0,
            'current_clicks' => 0,
        ]);

        SettingService::setRecruitmentCurrentCohortKey('direct-cohort');

        $this->get(route('apply.show', [
            'rm' => 'directrm456',
            'cohort' => 'direct-cohort',
        ]))
            ->assertOk();

        $variant->refresh();
        $this->assertSame(1, $variant->lifetime_clicks);
        $this->assertSame(1, $variant->current_clicks);
        $this->assertSame(1, RecruitmentMessageClick::where('recruitment_message_id', $variant->id)->count());
    }

    public function test_clicks_from_an_old_cohort_do_not_increment_current_metrics(): void
    {
        SettingService::setRecruitmentCurrentCohortKey('old-cohort');

        $variant = RecruitmentMessage::factory()->create([
            'tracking_key' => 'cohort123',
            'lifetime_clicks' => 0,
            'current_clicks' => 0,
        ]);

        $oldTrackingUrl = $variant->trackingUrl('old-cohort');
        app(RecruitmentService::class)->resetCurrentCohort();

        $this->get($oldTrackingUrl)->assertRedirect();

        $variant->refresh();
        $this->assertSame(1, $variant->lifetime_clicks);
        $this->assertSame(0, $variant->current_clicks);

        $this->get($variant->tracking_url)->assertRedirect();

        $variant->refresh();
        $this->assertSame(2, $variant->lifetime_clicks);
        $this->assertSame(1, $variant->current_clicks);
    }

    public function test_tracking_link_deduplicates_same_ip_across_sessions(): void
    {
        SettingService::setRecruitmentCurrentCohortKey('dedupe-cohort');

        $variant = RecruitmentMessage::factory()->create([
            'lifetime_clicks' => 0,
            'current_clicks' => 0,
        ]);

        $service = app(RecruitmentService::class);

        $this->assertTrue($service->recordClick($variant, '203.0.113.10', 'Browser A', 'dedupe-cohort'));
        $this->assertFalse($service->recordClick($variant, '203.0.113.10', 'Browser B', 'dedupe-cohort'));

        $variant->refresh();
        $this->assertSame(1, $variant->lifetime_clicks);
        $this->assertSame(1, $variant->current_clicks);
        $this->assertSame(1, RecruitmentMessageClick::where('recruitment_message_id', $variant->id)->count());
    }

    public function test_invalid_cohort_key_is_recorded_as_legacy_without_affecting_current_metrics(): void
    {
        SettingService::setRecruitmentCurrentCohortKey('current-cohort');
        $variant = RecruitmentMessage::factory()->create([
            'tracking_key' => 'invalidcohort',
            'lifetime_clicks' => 0,
            'current_clicks' => 0,
        ]);

        $this->get(route('recruitment.click', [
            'tracking_key' => $variant->tracking_key,
            'cohort' => str_repeat('x', 100),
        ]))->assertRedirect();

        $variant->refresh();
        $this->assertSame(1, $variant->lifetime_clicks);
        $this->assertSame(0, $variant->current_clicks);
        $this->assertDatabaseHas('recruitment_message_clicks', [
            'recruitment_message_id' => $variant->id,
            'cohort_key' => 'legacy',
        ]);
    }

    public function test_recruitment_cycle_distributes_messages_in_ab_testing_pattern(): void
    {
        SettingService::setRecruitmentEnabled(true);
        SettingService::setRecruitmentFollowUpEnabled(false);
        RecruitmentMessage::query()->delete();

        $variantA = RecruitmentMessage::factory()->create([
            'name' => 'Variant A',
            'subject' => 'Variant A Subject',
            'message' => '<p>Apply at {apply_link}</p>',
            'current_sends' => 0,
            'lifetime_sends' => 0,
        ]);

        $variantB = RecruitmentMessage::factory()->create([
            'name' => 'Variant B',
            'subject' => 'Variant B Subject',
            'message' => '<p>Join via {apply_url}</p>',
            'current_sends' => 0,
            'lifetime_sends' => 0,
        ]);

        $nations = collect([
            $this->makeNation(1001, 'Leader One'),
            $this->makeNation(1002, 'Leader Two'),
            $this->makeNation(1003, 'Leader Three'),
            $this->makeNation(1004, 'Leader Four'),
        ]);

        $sentMessages = [];

        $messageService = Mockery::mock(PWMessageService::class);
        $messageService->shouldReceive('sendMessage')
            ->times(4)
            ->andReturnUsing(function ($nationId, $subject, $body) use (&$sentMessages) {
                $sentMessages[] = [
                    'nation_id' => $nationId,
                    'subject' => $subject,
                    'body' => $body,
                ];

                return true;
            });

        $recruitmentService = new class($messageService, $nations) extends RecruitmentService
        {
            public function __construct(PWMessageService $service, private readonly Collection $testNations)
            {
                parent::__construct($service);
            }

            protected function fetchRecentNations(): Collection
            {
                return $this->testNations;
            }
        };

        $recruitmentService->runRecruitmentCycle();

        $this->assertCount(4, $sentMessages);

        $variantA->refresh();
        $variantB->refresh();

        // Balanced 2 sends each for 4 nations
        $this->assertSame(2, $variantA->current_sends);
        $this->assertSame(2, $variantA->lifetime_sends);
        $this->assertSame(2, $variantB->current_sends);
        $this->assertSame(2, $variantB->lifetime_sends);

        // Verify tracked URLs were injected
        $this->assertStringContainsString($variantA->tracking_url, $sentMessages[0]['body']);
        $this->assertStringContainsString($variantB->tracking_url, $sentMessages[1]['body']);

        // Verify recruited_nations table holds recruitment_message_id
        $this->assertSame(4, RecruitedNation::count());
        $this->assertSame(2, RecruitedNation::where('recruitment_message_id', $variantA->id)->count());
        $this->assertSame(2, RecruitedNation::where('recruitment_message_id', $variantB->id)->count());
    }

    public function test_admin_can_send_test_message_for_specific_variant(): void
    {
        $admin = $this->createAdmin(['manage-recruitment']);

        $variant = RecruitmentMessage::factory()->create([
            'name' => 'Pitch X',
            'subject' => 'Pitch X Subject',
            'message' => '<p>Pitch body {apply_link}</p>',
        ]);

        $this->mock(PWMessageService::class)
            ->shouldReceive('sendMessage')
            ->once()
            ->withArgs(function ($nationId, $subject, $body) use ($admin, $variant) {
                return $nationId === $admin->nation_id
                    && $subject === 'Pitch X Subject'
                    && str_contains($body, $variant->tracking_url);
            })
            ->andReturn(true);

        $this->actingAs($admin)
            ->post(route('admin.recruitment.test'), [
                'type' => 'variant',
                'message_id' => $variant->id,
            ])
            ->assertRedirect(route('admin.recruitment.index'))
            ->assertSessionHas('alert-type', 'success');
    }

    public function test_artisan_recruit_nations_command_executes_cycle_and_rotates_variants_in_least_sent_order(): void
    {
        SettingService::setRecruitmentEnabled(true);
        SettingService::setRecruitmentFollowUpEnabled(true);
        Queue::fake([SendRecruitmentMessage::class]);
        RecruitmentMessage::query()->delete();

        $variantA = RecruitmentMessage::factory()->create([
            'name' => 'Variant Alpha',
            'subject' => 'Subject Alpha',
            'message' => '<p>Alpha {apply_link}</p>',
            'current_sends' => 2,
            'lifetime_sends' => 10,
        ]);

        $variantB = RecruitmentMessage::factory()->create([
            'name' => 'Variant Bravo',
            'subject' => 'Subject Bravo',
            'message' => '<p>Bravo {apply_url}</p>',
            'current_sends' => 0,
            'lifetime_sends' => 5,
        ]);

        $variantC = RecruitmentMessage::factory()->create([
            'name' => 'Variant Charlie',
            'subject' => 'Subject Charlie',
            'message' => '<p>Charlie pitch</p>',
            'current_sends' => 1,
            'lifetime_sends' => 8,
        ]);

        $nations = collect([
            $this->makeNation(2001, 'Leader 2001'),
            $this->makeNation(2002, 'Leader 2002'),
            $this->makeNation(2003, 'Leader 2003'),
            $this->makeNation(2004, 'Leader 2004'),
        ]);

        $sentMessages = [];
        $messageService = Mockery::mock(PWMessageService::class);
        $messageService->shouldReceive('sendMessage')
            ->times(4)
            ->andReturnUsing(function ($nationId, $subject, $body) use (&$sentMessages) {
                $sentMessages[] = [
                    'nation_id' => $nationId,
                    'subject' => $subject,
                    'body' => $body,
                ];

                return true;
            });

        $this->bindTestRecruitmentService($messageService, $nations);

        $this->artisan('recruit:nations')->assertSuccessful();

        $this->assertCount(4, $sentMessages);

        // Verification of rotation sequence based on least-sent balancing:
        // Nation 2001: picks Variant B (0 sends). B becomes 1.
        $this->assertSame(2001, $sentMessages[0]['nation_id']);
        $this->assertSame('Subject Bravo', $sentMessages[0]['subject']);
        $this->assertStringContainsString($variantB->tracking_url, $sentMessages[0]['body']);

        // Nation 2002: B (1 send) vs C (1 send) vs A (2 sends). B has lower id than C, so picks B. B becomes 2.
        $this->assertSame(2002, $sentMessages[1]['nation_id']);
        $this->assertSame('Subject Bravo', $sentMessages[1]['subject']);
        $this->assertStringContainsString($variantB->tracking_url, $sentMessages[1]['body']);

        // Nation 2003: picks Variant C (1 send). C becomes 2.
        $this->assertSame(2003, $sentMessages[2]['nation_id']);
        $this->assertSame('Subject Charlie', $sentMessages[2]['subject']);
        $this->assertStringContainsString($variantC->tracking_url, $sentMessages[2]['body']);

        // Nation 2004: all three variants have 2 sends; picks Variant A (lowest id). A becomes 3.
        $this->assertSame(2004, $sentMessages[3]['nation_id']);
        $this->assertSame('Subject Alpha', $sentMessages[3]['subject']);
        $this->assertStringContainsString($variantA->tracking_url, $sentMessages[3]['body']);

        // Database sends updated
        $variantA->refresh();
        $variantB->refresh();
        $variantC->refresh();

        $this->assertSame(3, $variantA->current_sends);
        $this->assertSame(11, $variantA->lifetime_sends);
        $this->assertSame(2, $variantB->current_sends);
        $this->assertSame(7, $variantB->lifetime_sends);
        $this->assertSame(2, $variantC->current_sends);
        $this->assertSame(9, $variantC->lifetime_sends);

        // Recruited nations records created with correct variant mappings
        $this->assertSame(4, RecruitedNation::count());
        $this->assertSame(1, RecruitedNation::where('recruitment_message_id', $variantA->id)->count());
        $this->assertSame(2, RecruitedNation::where('recruitment_message_id', $variantB->id)->count());
        $this->assertSame(1, RecruitedNation::where('recruitment_message_id', $variantC->id)->count());

        // Follow-up jobs queued for each recruited nation
        Queue::assertPushed(SendRecruitmentMessage::class, 4);
    }

    public function test_artisan_recruit_nations_falls_back_to_settings_when_no_active_variants_exist(): void
    {
        SettingService::setRecruitmentEnabled(true);
        SettingService::setRecruitmentFollowUpEnabled(true);
        SettingService::setRecruitmentPrimarySubject('Global Default Subject');
        SettingService::setRecruitmentPrimaryMessage('<p>Global Default Body</p>');
        Queue::fake([SendRecruitmentMessage::class]);

        // Ensure all variants in the message pool are paused/inactive so system falls back to settings
        RecruitmentMessage::query()->update(['is_active' => false]);

        $nations = collect([
            $this->makeNation(3001, 'Leader 3001'),
            $this->makeNation(3002, 'Leader 3002'),
        ]);

        $sentMessages = [];
        $messageService = Mockery::mock(PWMessageService::class);
        $messageService->shouldReceive('sendMessage')
            ->twice()
            ->andReturnUsing(function ($nationId, $subject, $body) use (&$sentMessages) {
                $sentMessages[] = [
                    'nation_id' => $nationId,
                    'subject' => $subject,
                    'body' => $body,
                ];

                return true;
            });

        $this->bindTestRecruitmentService($messageService, $nations);

        $this->artisan('recruit:nations')->assertSuccessful();

        $this->assertCount(2, $sentMessages);
        $this->assertSame('Global Default Subject', $sentMessages[0]['subject']);
        $this->assertSame('<p>Global Default Body</p>', $sentMessages[0]['body']);
        $this->assertSame('Global Default Subject', $sentMessages[1]['subject']);
        $this->assertSame('<p>Global Default Body</p>', $sentMessages[1]['body']);

        // Null recruitment_message_id preserves backwards compatibility
        $records = RecruitedNation::whereIn('nation_id', [3001, 3002])->get();
        $this->assertCount(2, $records);
        $this->assertNull($records[0]->recruitment_message_id);
        $this->assertNull($records[1]->recruitment_message_id);

        Queue::assertPushed(SendRecruitmentMessage::class, 2);
    }

    public function test_recruitment_cycle_skips_nations_already_recruited(): void
    {
        SettingService::setRecruitmentEnabled(true);
        RecruitmentMessage::query()->delete();

        $variant = RecruitmentMessage::factory()->create([
            'is_active' => true,
            'current_sends' => 0,
            'lifetime_sends' => 0,
        ]);

        RecruitedNation::create([
            'nation_id' => 4001,
            'primary_sent_at' => now()->subDay(),
        ]);

        $nations = collect([
            $this->makeNation(4001, 'Already Recruited'),
            $this->makeNation(4002, 'Brand New Nation'),
        ]);

        $messageService = Mockery::mock(PWMessageService::class);
        $messageService->shouldReceive('sendMessage')
            ->once()
            ->withArgs(function ($nationId) {
                return $nationId === 4002;
            })
            ->andReturn(true);

        $this->bindTestRecruitmentService($messageService, $nations);

        $this->artisan('recruit:nations')->assertSuccessful();

        $variant->refresh();
        $this->assertSame(1, $variant->current_sends);
        $this->assertSame(1, $variant->lifetime_sends);
        $this->assertSame(2, RecruitedNation::count());
    }

    public function test_recruitment_cycle_respects_disabled_setting(): void
    {
        SettingService::setRecruitmentEnabled(false);
        RecruitmentMessage::query()->delete();

        RecruitmentMessage::factory()->create(['is_active' => true]);

        $nations = collect([
            $this->makeNation(5001, 'Leader 5001'),
        ]);

        $messageService = Mockery::mock(PWMessageService::class);
        $messageService->shouldNotReceive('sendMessage');

        $this->bindTestRecruitmentService($messageService, $nations);

        $this->artisan('recruit:nations')->assertSuccessful();

        $this->assertSame(0, RecruitedNation::count());
    }

    public function test_multi_cycle_rotation_persists_across_separate_command_runs(): void
    {
        SettingService::setRecruitmentEnabled(true);
        SettingService::setRecruitmentFollowUpEnabled(false);
        RecruitmentMessage::query()->delete();

        $variantA = RecruitmentMessage::factory()->create([
            'name' => 'Variant Alpha',
            'is_active' => true,
            'current_sends' => 0,
            'lifetime_sends' => 0,
        ]);

        $variantB = RecruitmentMessage::factory()->create([
            'name' => 'Variant Bravo',
            'is_active' => true,
            'current_sends' => 0,
            'lifetime_sends' => 0,
        ]);

        $messageService = Mockery::mock(PWMessageService::class);
        $messageService->shouldReceive('sendMessage')->times(3)->andReturn(true);

        // Cycle 1: first cron execution with 1 nation
        $service1 = $this->bindTestRecruitmentService($messageService, collect([
            $this->makeNation(6001, 'Leader 6001'),
        ]));
        $this->artisan('recruit:nations')->assertSuccessful();

        $variantA->refresh();
        $variantB->refresh();
        $this->assertSame(1, $variantA->current_sends);
        $this->assertSame(0, $variantB->current_sends);

        // Cycle 2: second cron execution with 1 nation (picks Variant B because B has 0 sends)
        $this->bindTestRecruitmentService($messageService, collect([
            $this->makeNation(6002, 'Leader 6002'),
        ]));
        $this->artisan('recruit:nations')->assertSuccessful();

        $variantA->refresh();
        $variantB->refresh();
        $this->assertSame(1, $variantA->current_sends);
        $this->assertSame(1, $variantB->current_sends);

        // Cycle 3: third cron execution with 1 nation (both have 1 send, picks Variant A due to tiebreak)
        $this->bindTestRecruitmentService($messageService, collect([
            $this->makeNation(6003, 'Leader 6003'),
        ]));
        $this->artisan('recruit:nations')->assertSuccessful();

        $variantA->refresh();
        $variantB->refresh();
        $this->assertSame(2, $variantA->current_sends);
        $this->assertSame(1, $variantB->current_sends);
    }

    public function test_send_recruitment_message_job_executes_follow_up_for_unaligned_nation(): void
    {
        SettingService::setRecruitmentEnabled(true);
        SettingService::setRecruitmentFollowUpEnabled(true);
        SettingService::setRecruitmentFollowUpSubject('Are you still looking for a home?');
        SettingService::setRecruitmentFollowUpMessage('Join our alliance today!');

        $record = RecruitedNation::create([
            'nation_id' => 7001,
            'primary_sent_at' => now()->subHours(61),
            'follow_up_scheduled_for' => now()->subHour(),
        ]);

        Http::fake([
            '*' => Http::response([
                'data' => [
                    'nations' => [
                        'data' => [
                            [
                                'id' => 7001,
                                'nation_name' => 'Unaligned Nation',
                                'leader_name' => 'Solo Leader',
                                'alliance_id' => null,
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $messageService = Mockery::mock(PWMessageService::class);
        $messageService->shouldReceive('sendMessage')
            ->once()
            ->with(7001, 'Are you still looking for a home?', 'Join our alliance today!')
            ->andReturn(true);

        $this->app->instance(PWMessageService::class, $messageService);

        SendRecruitmentMessage::dispatchSync($record->id);

        $record->refresh();
        $this->assertNotNull($record->follow_up_sent_at);
    }

    public function test_send_recruitment_message_job_skips_nation_if_already_joined_alliance(): void
    {
        SettingService::setRecruitmentEnabled(true);
        SettingService::setRecruitmentFollowUpEnabled(true);

        $record = RecruitedNation::create([
            'nation_id' => 7002,
            'primary_sent_at' => now()->subHours(61),
        ]);

        Http::fake([
            '*' => Http::response([
                'data' => [
                    'nations' => [
                        'data' => [
                            [
                                'id' => 7002,
                                'nation_name' => 'Aligned Nation',
                                'leader_name' => 'Alliance Leader',
                                'alliance_id' => 9999,
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $messageService = Mockery::mock(PWMessageService::class);
        $messageService->shouldNotReceive('sendMessage');

        $this->app->instance(PWMessageService::class, $messageService);

        SendRecruitmentMessage::dispatchSync($record->id);

        $record->refresh();
        $this->assertNull($record->follow_up_sent_at);
    }

    public function test_send_recruitment_message_job_skips_when_follow_up_disabled(): void
    {
        SettingService::setRecruitmentEnabled(true);
        SettingService::setRecruitmentFollowUpEnabled(false);

        $record = RecruitedNation::create([
            'nation_id' => 7003,
            'primary_sent_at' => now()->subHours(61),
        ]);

        $messageService = Mockery::mock(PWMessageService::class);
        $messageService->shouldNotReceive('sendMessage');

        $this->app->instance(PWMessageService::class, $messageService);

        SendRecruitmentMessage::dispatchSync($record->id);

        $record->refresh();
        $this->assertNull($record->follow_up_sent_at);
    }

    /**
     * @param  array<int, string>  $permissions
     */
    private function createAdmin(array $permissions): User
    {
        $admin = $this->createVerifiedAdmin(['nation_id' => 980001]);
        $this->attachDiscordAccount($admin, ['discord_id' => '1980001']);

        return $this->grantPermissions($admin, $permissions);
    }

    private function makeNation(int $id, string $leaderName): Nation
    {
        $nation = new Nation;
        $nation->id = $id;
        $nation->leader_name = $leaderName;
        $nation->alliance_id = null;

        return $nation;
    }

    private function bindTestRecruitmentService(PWMessageService $messageService, Collection $nations): RecruitmentService
    {
        $service = new class($messageService, $nations) extends RecruitmentService
        {
            public function __construct(PWMessageService $service, private Collection $testNations)
            {
                parent::__construct($service);
            }

            public function setTestNations(Collection $nations): void
            {
                $this->testNations = $nations;
            }

            protected function fetchRecentNations(): Collection
            {
                return $this->testNations;
            }
        };

        $this->app->instance(RecruitmentService::class, $service);

        return $service;
    }
}
