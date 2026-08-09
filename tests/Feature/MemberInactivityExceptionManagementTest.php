<?php

namespace Tests\Feature;

use App\Enums\MemberInactivityAutomation;
use App\Enums\MemberInactivityExceptionCategory;
use App\Models\AuditLog;
use App\Models\MemberInactivityException;
use App\Models\Nation;
use App\Models\User;
use App\Services\SettingService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTestUsers;
use Tests\TestCase;

class MemberInactivityExceptionManagementTest extends TestCase
{
    use BuildsTestUsers;
    use RefreshDatabase;

    public function test_authorized_staff_can_create_a_timezone_aware_exception_with_a_complete_audit_record(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-06 18:00:00 UTC'));
        config()->set('app.timezone', 'America/Chicago');

        $nation = Nation::factory()->create();
        $staff = $this->adminWithPermissions(['view-members', 'manage-member-exceptions']);

        $response = $this->actingAs($staff)->post(
            route('admin.members.inactivity-exceptions.store', $nation),
            $this->payload([
                'starts_at' => '2026-08-06T12:00',
                'ends_at' => '2026-08-08T12:00',
                'timezone' => 'America/Chicago',
                'member_reason' => 'Approved military leave pauses selected inactivity actions.',
                'private_notes' => 'Orders verified by personnel staff.',
            ]),
        );

        $response->assertRedirect(route('admin.members.show', $nation).'#inactivity-exceptions');

        $exception = MemberInactivityException::query()->sole();
        $this->assertSame(MemberInactivityExceptionCategory::MilitaryLeave, $exception->category);
        $this->assertSame('2026-08-06T17:00:00+00:00', $exception->starts_at->toIso8601String());
        $this->assertSame('2026-08-08T17:00:00+00:00', $exception->ends_at->toIso8601String());
        $this->assertSame($staff->id, $exception->approved_by_user_id);
        $this->assertSame($staff->id, $exception->last_reviewed_by_user_id);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'member_inactivity_exception_created',
            'actor_id' => $staff->id,
            'subject_id' => $exception->id,
        ]);
        $this->assertStringNotContainsString(
            'Orders verified by personnel staff.',
            json_encode(AuditLog::query()->where('action', 'member_inactivity_exception_created')->sole()->context),
        );
    }

    public function test_dedicated_permission_and_member_view_permission_are_both_required(): void
    {
        $nation = Nation::factory()->create();

        $viewOnly = $this->adminWithPermissions(['view-members']);
        $this->actingAs($viewOnly)
            ->post(route('admin.members.inactivity-exceptions.store', $nation), $this->payload())
            ->assertForbidden();

        $exceptionManagerWithoutMemberAccess = $this->adminWithPermissions(['manage-member-exceptions']);
        $this->actingAs($exceptionManagerWithoutMemberAccess)
            ->post(route('admin.members.inactivity-exceptions.store', $nation), $this->payload())
            ->assertForbidden();

        $this->assertDatabaseCount('member_inactivity_exceptions', 0);
    }

    public function test_overlapping_windows_are_rejected_but_adjacent_windows_are_allowed(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-06 12:00:00 UTC'));
        $nation = Nation::factory()->create();
        $staff = $this->adminWithPermissions(['view-members', 'manage-member-exceptions']);

        $this->actingAs($staff)->post(
            route('admin.members.inactivity-exceptions.store', $nation),
            $this->payload([
                'starts_at' => '2026-08-07T12:00',
                'ends_at' => '2026-08-09T12:00',
            ]),
        )->assertRedirect();

        $this->actingAs($staff)->post(
            route('admin.members.inactivity-exceptions.store', $nation),
            $this->payload([
                'starts_at' => '2026-08-08T12:00',
                'ends_at' => '2026-08-10T12:00',
            ]),
        )->assertSessionHasErrors('starts_at');

        $this->actingAs($staff)->post(
            route('admin.members.inactivity-exceptions.store', $nation),
            $this->payload([
                'starts_at' => '2026-08-09T12:00',
                'ends_at' => '2026-08-11T12:00',
            ]),
        )->assertRedirect();

        $this->assertDatabaseCount('member_inactivity_exceptions', 2);
    }

    public function test_end_time_and_valid_timezone_are_required(): void
    {
        $nation = Nation::factory()->create();
        $staff = $this->adminWithPermissions(['view-members', 'manage-member-exceptions']);

        $missingEnd = $this->payload();
        unset($missingEnd['ends_at']);

        $this->actingAs($staff)
            ->post(route('admin.members.inactivity-exceptions.store', $nation), $missingEnd)
            ->assertSessionHasErrors('ends_at');

        $this->actingAs($staff)
            ->post(route('admin.members.inactivity-exceptions.store', $nation), $this->payload([
                'timezone' => 'Mars/Olympus_Mons',
            ]))
            ->assertSessionHasErrors('timezone');

        $this->assertDatabaseCount('member_inactivity_exceptions', 0);
    }

    public function test_active_exception_can_only_be_extended_and_every_review_is_audited(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-06 12:00:00 UTC'));
        $nation = Nation::factory()->create();
        $staff = $this->adminWithPermissions(['view-members', 'manage-member-exceptions']);
        $exception = MemberInactivityException::factory()->create([
            'nation_id' => $nation->id,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDay(),
            'timezone' => 'UTC',
            'approved_by_user_id' => $staff->id,
            'last_reviewed_by_user_id' => $staff->id,
        ]);

        $this->actingAs($staff)->put(
            route('admin.members.inactivity-exceptions.update', [$nation, $exception]),
            $this->payload([
                'category' => MemberInactivityExceptionCategory::Vacation->value,
                'starts_at' => '2026-08-05T12:00',
                'ends_at' => '2026-08-08T12:00',
                'timezone' => 'UTC',
                'member_reason' => 'Leave extended after a documented review.',
            ]),
        )->assertRedirect();

        $this->assertSame('2026-08-08T12:00:00+00:00', $exception->fresh()->ends_at->toIso8601String());
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'member_inactivity_exception_updated',
            'actor_id' => $staff->id,
            'subject_id' => $exception->id,
        ]);

        $this->actingAs($staff)->put(
            route('admin.members.inactivity-exceptions.update', [$nation, $exception]),
            $this->payload([
                'category' => MemberInactivityExceptionCategory::Vacation->value,
                'starts_at' => '2026-08-05T12:00',
                'ends_at' => '2026-08-07T00:00',
                'timezone' => 'UTC',
            ]),
        )->assertSessionHasErrors('ends_at');

        $this->assertSame('2026-08-08T12:00:00+00:00', $exception->fresh()->ends_at->toIso8601String());
    }

    public function test_revocation_is_audited_and_preserves_the_record(): void
    {
        $nation = Nation::factory()->create();
        $staff = $this->adminWithPermissions(['view-members', 'manage-member-exceptions']);
        $exception = MemberInactivityException::factory()->create([
            'nation_id' => $nation->id,
            'approved_by_user_id' => $staff->id,
            'last_reviewed_by_user_id' => $staff->id,
        ]);

        $route = route('admin.members.inactivity-exceptions.destroy', [$nation, $exception]);
        $this->actingAs($staff)->delete($route, [
            'revocation_reason' => 'Member returned earlier than expected.',
        ])->assertRedirect();

        $this->assertNotNull($exception->fresh()->revoked_at);
        $this->assertDatabaseCount('member_inactivity_exceptions', 1);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'member_inactivity_exception_revoked',
            'subject_id' => $exception->id,
        ]);

        $this->actingAs($staff)->delete($route, [
            'revocation_reason' => 'Duplicate retry.',
        ])->assertRedirect();

        $this->assertSame(1, AuditLog::query()->where('action', 'member_inactivity_exception_revoked')->count());
    }

    public function test_nested_route_binding_prevents_cross_member_updates(): void
    {
        $staff = $this->adminWithPermissions(['view-members', 'manage-member-exceptions']);
        $owner = Nation::factory()->create();
        $otherNation = Nation::factory()->create();
        $exception = MemberInactivityException::factory()->create([
            'nation_id' => $owner->id,
            'approved_by_user_id' => $staff->id,
            'last_reviewed_by_user_id' => $staff->id,
        ]);
        $originalReason = $exception->member_reason;

        $this->actingAs($staff)
            ->put(
                route('admin.members.inactivity-exceptions.update', [$otherNation, $exception]),
                $this->payload(['member_reason' => 'Cross-member overwrite attempt.']),
            )
            ->assertNotFound();

        $this->assertSame($originalReason, $exception->fresh()->member_reason);
    }

    public function test_private_notes_are_staff_only_while_members_see_practical_effects(): void
    {
        $nation = Nation::factory()->create();
        $manager = $this->adminWithPermissions(['view-members', 'manage-member-exceptions']);
        $viewer = $this->adminWithPermissions(['view-members']);
        $member = $this->createVerifiedUser(['nation_id' => $nation->id]);
        $this->attachDiscordAccount($member);
        $this->enableTwoFactor($member);

        MemberInactivityException::factory()->create([
            'nation_id' => $nation->id,
            'member_reason' => 'Your in-game inactivity message is paused during approved leave.',
            'private_notes' => 'Private evidence reference CASE-9921.',
            'affected_automations' => [MemberInactivityAutomation::SendInGameMessage],
            'approved_by_user_id' => $manager->id,
            'last_reviewed_by_user_id' => $manager->id,
        ]);

        $this->actingAs($manager)
            ->get(route('admin.members.show', ['Nation' => $nation->id]))
            ->assertOk()
            ->assertSeeText('Private evidence reference CASE-9921.');

        $this->actingAs($viewer)
            ->get(route('admin.members.show', ['Nation' => $nation->id]))
            ->assertOk()
            ->assertDontSeeText('Private evidence reference CASE-9921.')
            ->assertDontSeeText('Leave and inactivity exceptions');

        $this->actingAs($member)
            ->get(route('user.dashboard'))
            ->assertOk()
            ->assertSeeText('Approved leave or temporary pause')
            ->assertSeeText('Your in-game inactivity message is paused during approved leave.')
            ->assertSeeText('In-game inactivity messages')
            ->assertDontSeeText('Private evidence reference CASE-9921.');
    }

    public function test_account_disablement_is_suppressed_only_when_that_automation_is_selected(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-06 12:00:00 UTC'));
        SettingService::setUserInactivityAutoDisableEnabled(true);
        SettingService::setUserInactivityAutoDisableDays(90);

        $protectedNation = Nation::factory()->create();
        $notificationOnlyNation = Nation::factory()->create();
        $inactiveAt = now()->subDays(120);
        $protectedUser = User::factory()->create([
            'nation_id' => $protectedNation->id,
            'last_active_at' => $inactiveAt,
            'created_at' => $inactiveAt,
        ]);
        $notificationOnlyUser = User::factory()->create([
            'nation_id' => $notificationOnlyNation->id,
            'last_active_at' => $inactiveAt,
            'created_at' => $inactiveAt,
        ]);

        MemberInactivityException::factory()->create([
            'nation_id' => $protectedNation->id,
            'affected_automations' => [MemberInactivityAutomation::DisableAccount],
        ]);
        MemberInactivityException::factory()->create([
            'nation_id' => $notificationOnlyNation->id,
            'affected_automations' => [MemberInactivityAutomation::SendDiscordNotification],
        ]);

        $this->artisan('users:disable-inactive')->assertSuccessful();

        $this->assertFalse($protectedUser->fresh()->disabled);
        $this->assertTrue($notificationOnlyUser->fresh()->disabled);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'category' => MemberInactivityExceptionCategory::MilitaryLeave->value,
            'starts_at' => now()->setTimezone(config('app.timezone'))->startOfHour()->format('Y-m-d\TH:i'),
            'ends_at' => now()->setTimezone(config('app.timezone'))->addWeek()->startOfHour()->format('Y-m-d\TH:i'),
            'timezone' => (string) config('app.timezone'),
            'member_reason' => 'Approved leave pauses selected inactivity automations.',
            'private_notes' => 'Staff-only verification notes.',
            'affected_automations' => collect(MemberInactivityAutomation::cases())->pluck('value')->all(),
            ...$overrides,
        ];
    }

    /**
     * @param  list<string>  $permissions
     */
    private function adminWithPermissions(array $permissions): User
    {
        $admin = $this->createVerifiedAdmin();
        $this->attachDiscordAccount($admin);
        $this->enableTwoFactor($admin);

        return $this->grantPermissions($admin, $permissions);
    }
}
