<?php

namespace Tests\Feature\Admin;

use App\GraphQL\Models\Nation;
use App\Models\RecruitedNation;
use App\Models\RecruitmentMessage;
use App\Models\RecruitmentMessageClick;
use App\Models\User;
use App\Services\PWMessageService;
use App\Services\RecruitmentService;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
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

        $response = $this->get(route('recruitment.click', ['tracking_key' => 'track123abc']));

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

        $this->get(route('recruitment.click', ['tracking_key' => 'dedup789xyz']))
            ->assertRedirect();

        $this->assertSame(1, $variant->fresh()->lifetime_clicks);
        $this->assertSame(1, $variant->fresh()->current_clicks);
        $this->assertSame(1, RecruitmentMessageClick::where('recruitment_message_id', $variant->id)->count());

        // Repeat click in same session
        $this->get(route('recruitment.click', ['tracking_key' => 'dedup789xyz']))
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

        $this->get(route('apply.show', ['rm' => 'directrm456']))
            ->assertOk();

        $variant->refresh();
        $this->assertSame(1, $variant->lifetime_clicks);
        $this->assertSame(1, $variant->current_clicks);
        $this->assertSame(1, RecruitmentMessageClick::where('recruitment_message_id', $variant->id)->count());
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
}
