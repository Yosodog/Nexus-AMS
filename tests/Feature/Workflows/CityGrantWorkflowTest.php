<?php

namespace Tests\Feature\Workflows;

use App\Models\Account;
use App\Models\CityGrant;
use App\Models\CityGrantRequest;
use App\Models\GrowthCircleEnrollment;
use App\Models\Nation;
use App\Models\User;
use App\Notifications\CityGrantNotification;
use App\Services\AuthoritativeNationMembershipService;
use App\Services\CityCostService;
use App\Services\PWHelperService;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Concerns\BuildsTestUsers;
use Tests\TestCase;

class CityGrantWorkflowTest extends TestCase
{
    use BuildsTestUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Cache::forever('alliances:membership:ids', [777]);
        Notification::fake();
        SettingService::setCityAverage(15.0);
        SettingService::setCityAverageUpdatedAt(now());
        SettingService::setGrantApprovalsEnabled(true);
        $this->app->instance(
            AuthoritativeNationMembershipService::class,
            $this->createStub(AuthoritativeNationMembershipService::class),
        );
    }

    public function test_member_can_request_a_city_grant_with_an_owned_account(): void
    {
        [$user, $nation, $account] = $this->createMemberWithAccount();
        $grant = $this->createCityGrant($nation->num_cities + 1);

        $this->actingAs($user)
            ->post(route('grants.city.request'), [
                'account_id' => $account->id,
            ])
            ->assertRedirect(route('grants.city'))
            ->assertSessionHas('alert-type', 'success');

        $this->assertDatabaseHas('city_grant_requests', [
            'nation_id' => $nation->id,
            'account_id' => $account->id,
            'city_number' => $grant->city_number,
            'status' => 'pending',
            'pending_key' => 1,
        ]);
    }

    public function test_member_cannot_request_a_duplicate_pending_city_grant(): void
    {
        [$user, $nation, $account] = $this->createMemberWithAccount();
        $grant = $this->createCityGrant($nation->num_cities + 1);

        CityGrantRequest::query()->create([
            'city_number' => $grant->city_number,
            'grant_amount' => 250000,
            'nation_id' => $nation->id,
            'account_id' => $account->id,
            'status' => 'pending',
            'pending_key' => 1,
        ]);

        $this->actingAs($user)
            ->from(route('grants.city'))
            ->post(route('grants.city.request'), [
                'account_id' => $account->id,
            ])
            ->assertRedirect(route('grants.city'))
            ->assertSessionHas('alert-type', 'error')
            ->assertSessionHas(
                'alert-message',
                'You already have a city grant request awaiting review. Wait for a decision before submitting another request.',
            )
            ->assertSessionHasInput('account_id', $account->id);

        $this->assertSame(1, CityGrantRequest::query()->count());
    }

    public function test_member_cannot_request_a_city_grant_without_required_projects(): void
    {
        [$user, $nation, $account] = $this->createMemberWithAccount();
        $grant = $this->createCityGrant($nation->num_cities + 1);
        $grant->update([
            'requirements' => ['required_projects' => ['Urban Planning']],
        ]);

        $this->actingAs($user)
            ->from(route('grants.city'))
            ->post(route('grants.city.request'), [
                'account_id' => $account->id,
            ])
            ->assertRedirect(route('grants.city'))
            ->assertSessionHas('alert-type', 'error')
            ->assertSessionHas(
                'alert-message',
                'You are not currently eligible for this city grant. Review the eligibility requirements shown on this page, correct any unmet items, and try again.',
            );

        $this->assertDatabaseCount('city_grant_requests', 0);
    }

    public function test_member_receives_an_actionable_cooldown_failure(): void
    {
        [$user, $nation, $account] = $this->createMemberWithAccount();
        $grant = $this->createCityGrant($nation->num_cities + 1);
        $grant->update([
            'requirements' => [
                'group' => 'all',
                'rules' => [[
                    'field' => 'turns_since_last_city',
                    'operator' => 'gte',
                    'value' => 120,
                    'message' => '',
                ]],
            ],
        ]);

        $this->actingAs($user)
            ->post(route('grants.city.request'), ['account_id' => $account->id])
            ->assertRedirect(route('grants.city'))
            ->assertSessionHas(
                'alert-message',
                'Your city or project purchase cooldown is still active. Wait until the required turns have passed, refresh your nation data, and try again.',
            );

        $this->assertDatabaseCount('city_grant_requests', 0);
    }

    public function test_passing_cooldown_rule_does_not_mask_an_eligibility_failure(): void
    {
        [$user, $nation, $account] = $this->createMemberWithAccount();
        $grant = $this->createCityGrant($nation->num_cities + 1);
        $grant->update([
            'requirements' => [
                'group' => 'all',
                'rules' => [
                    [
                        'field' => 'turns_since_last_city',
                        'operator' => 'gte',
                        'value' => 0,
                        'message' => '',
                    ],
                    [
                        'field' => 'projects',
                        'operator' => 'contains_all',
                        'value' => ['Urban Planning'],
                        'message' => '',
                    ],
                ],
            ],
        ]);

        $this->actingAs($user)
            ->post(route('grants.city.request'), ['account_id' => $account->id])
            ->assertRedirect(route('grants.city'))
            ->assertSessionHas(
                'alert-message',
                'You are not currently eligible for this city grant. Review the eligibility requirements shown on this page, correct any unmet items, and try again.',
            );

        $this->assertDatabaseCount('city_grant_requests', 0);
    }

    public function test_member_receives_an_actionable_missing_audit_failure(): void
    {
        [$user, $nation, $account] = $this->createMemberWithAccount();
        $grant = $this->createCityGrant($nation->num_cities + 1);
        $grant->update([
            'requirements' => [
                'group' => 'all',
                'rules' => [[
                    'field' => 'mmr_score',
                    'operator' => 'gte',
                    'value' => 1,
                    'message' => '',
                ]],
            ],
        ]);

        $this->actingAs($user)
            ->post(route('grants.city.request'), ['account_id' => $account->id])
            ->assertRedirect(route('grants.city'))
            ->assertSessionHas(
                'alert-message',
                'A current nation audit is required for this city grant. Complete or refresh your audit, then try again.',
            );

        $this->assertDatabaseCount('city_grant_requests', 0);
    }

    public function test_member_receives_an_actionable_insufficient_data_failure(): void
    {
        [$user, $nation, $account] = $this->createMemberWithAccount();
        $grant = $this->createCityGrant($nation->num_cities + 1);
        $grant->update([
            'requirements' => [
                'group' => 'all',
                'rules' => [[
                    'field' => 'total_infrastructure',
                    'operator' => 'gte',
                    'value' => 1,
                    'message' => '',
                ]],
            ],
        ]);

        $this->actingAs($user)
            ->post(route('grants.city.request'), ['account_id' => $account->id])
            ->assertRedirect(route('grants.city'))
            ->assertSessionHas(
                'alert-message',
                'There is not enough current nation data to verify this request. Refresh your nation data and try again; contact the economics team if the problem continues.',
            );

        $this->assertDatabaseCount('city_grant_requests', 0);
    }

    public function test_member_receives_an_actionable_external_outage_failure(): void
    {
        [$user, $nation, $account] = $this->createMemberWithAccount();
        $this->createCityGrant($nation->num_cities + 1);

        $cityCostService = $this->createMock(CityCostService::class);
        $cityCostService->expects($this->once())
            ->method('calculateGrantAmount')
            ->willReturn(null);
        $this->app->instance(CityCostService::class, $cityCostService);

        $this->actingAs($user)
            ->post(route('grants.city.request'), ['account_id' => $account->id])
            ->assertRedirect(route('grants.city'))
            ->assertSessionHas(
                'alert-message',
                'City-cost data is temporarily unavailable, so no request was submitted. Please try again later.',
            )
            ->assertSessionHasInput('account_id', $account->id);

        $this->assertDatabaseCount('city_grant_requests', 0);
    }

    public function test_database_pending_guard_returns_the_pending_failure_when_a_race_is_detected(): void
    {
        [$user, $nation, $account] = $this->createMemberWithAccount();
        $grant = $this->createCityGrant($nation->num_cities + 1);
        $eventName = 'eloquent.creating: '.CityGrantRequest::class;
        $insertedCompetingRequest = false;

        Event::listen($eventName, function () use (&$insertedCompetingRequest, $grant, $nation, $account): void {
            if ($insertedCompetingRequest) {
                return;
            }

            $insertedCompetingRequest = true;

            DB::table('city_grant_requests')->insert([
                'city_number' => $grant->city_number,
                'grant_amount' => 250000,
                'nation_id' => $nation->id,
                'account_id' => $account->id,
                'status' => 'pending',
                'pending_key' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        try {
            $this->actingAs($user)
                ->post(route('grants.city.request'), ['account_id' => $account->id])
                ->assertRedirect(route('grants.city'))
                ->assertSessionHas(
                    'alert-message',
                    'You already have a city grant request awaiting review. Wait for a decision before submitting another request.',
                );
        } finally {
            Event::forget($eventName);
        }

        $this->assertTrue($insertedCompetingRequest);
        $this->assertDatabaseCount('city_grant_requests', 0);
    }

    public function test_unknown_city_grant_failure_is_safe_and_includes_a_logged_reference(): void
    {
        [$user, $nation, $account] = $this->createMemberWithAccount();
        $this->createCityGrant($nation->num_cities + 1);
        Log::spy();

        $cityCostService = $this->createMock(CityCostService::class);
        $cityCostService->expects($this->once())
            ->method('calculateGrantAmount')
            ->willThrowException(new RuntimeException('Internal provider response contained staff-only details.'));
        $this->app->instance(CityCostService::class, $cityCostService);

        $referenceId = null;

        $this->actingAs($user)
            ->post(route('grants.city.request'), ['account_id' => $account->id])
            ->assertRedirect(route('grants.city'))
            ->assertSessionHas('alert-message', function (string $message) use (&$referenceId): bool {
                $matched = preg_match('/reference ([0-9a-f-]{36})\.$/', $message, $matches) === 1;
                $referenceId = $matches[1] ?? null;

                return $matched
                    && ! str_contains($message, 'staff-only details');
            })
            ->assertSessionHasInput('account_id', $account->id);

        Log::shouldHaveReceived('error')->once()->withArgs(
            fn (string $message, array $context): bool => $message === 'City grant request failed unexpectedly.'
                && $context['reference_id'] === $referenceId
                && $context['nation_id'] === $nation->id
                && $context['account_id'] === $account->id
                && $context['exception_class'] === RuntimeException::class
        );

        $this->assertNotNull($referenceId);
        $this->assertDatabaseCount('city_grant_requests', 0);
    }

    public function test_member_cannot_request_a_city_grant_that_requires_growth_circle_enrollment(): void
    {
        [$user, $nation, $account] = $this->createMemberWithAccount();
        $grant = $this->createCityGrant($nation->num_cities + 1);
        $grant->update([
            'requirements' => $this->growthCircleEnrollmentRequirement(),
        ]);

        $this->actingAs($user)
            ->from(route('grants.city'))
            ->post(route('grants.city.request'), [
                'account_id' => $account->id,
            ])
            ->assertRedirect(route('grants.city'))
            ->assertSessionHas('alert-type', 'error')
            ->assertSessionHas(
                'alert-message',
                'You are not currently eligible for this city grant. Review the eligibility requirements shown on this page, correct any unmet items, and try again.',
            );

        $this->assertDatabaseCount('city_grant_requests', 0);
    }

    public function test_growth_circle_member_can_request_a_city_grant_that_requires_enrollment(): void
    {
        [$user, $nation, $account] = $this->createMemberWithAccount();
        $grant = $this->createCityGrant($nation->num_cities + 1);
        $grant->update([
            'requirements' => $this->growthCircleEnrollmentRequirement(),
        ]);

        GrowthCircleEnrollment::query()->create([
            'nation_id' => $nation->id,
            'account_id' => $account->id,
            'previous_tax_id' => null,
            'enrolled_at' => now(),
        ]);

        $this->actingAs($user)
            ->post(route('grants.city.request'), [
                'account_id' => $account->id,
            ])
            ->assertRedirect(route('grants.city'))
            ->assertSessionHas('alert-type', 'success');

        $this->assertDatabaseHas('city_grant_requests', [
            'nation_id' => $nation->id,
            'account_id' => $account->id,
            'city_number' => $grant->city_number,
            'status' => 'pending',
            'pending_key' => 1,
        ]);
    }

    public function test_admin_can_approve_a_pending_city_grant_request(): void
    {
        [$user, $nation, $account] = $this->createMemberWithAccount();
        $grant = $this->createCityGrant($nation->num_cities + 1);
        $request = CityGrantRequest::query()->create([
            'city_number' => $grant->city_number,
            'grant_amount' => 320000,
            'nation_id' => $nation->id,
            'account_id' => $account->id,
            'status' => 'pending',
            'pending_key' => 1,
        ]);
        $admin = $this->createAdminWithPermission('manage-city-grants');
        $membershipValidator = $this->createMock(AuthoritativeNationMembershipService::class);
        $membershipValidator->expects($this->once())
            ->method('validate')
            ->with($nation->id)
            ->willReturnCallback(function () use ($account, $request): void {
                $this->assertSame(0.0, (float) $account->fresh()->money);
                $this->assertSame('pending', $request->fresh()->status);
            });
        $this->app->instance(AuthoritativeNationMembershipService::class, $membershipValidator);

        $this->actingAs($admin)
            ->from(route('admin.grants.city'))
            ->post(route('admin.grants.city.approve', ['CityGrantRequest' => $request->id]))
            ->assertRedirect(route('admin.grants.city'))
            ->assertSessionHas('alert-type', 'success');

        $request->refresh();
        $account->refresh();

        $this->assertSame('approved', $request->status);
        $this->assertNull($request->pending_key);
        $this->assertNotNull($request->approved_at);
        $this->assertSame('320000.00', number_format((float) $account->money, 2, '.', ''));

        Notification::assertSentTo(
            $nation,
            CityGrantNotification::class,
            fn (CityGrantNotification $notification): bool => $notification->status === 'approved'
                && $notification->request->is($request)
        );
    }

    public function test_admin_cannot_approve_after_required_project_eligibility_changes(): void
    {
        [$user, $nation, $account] = $this->createMemberWithAccount();
        $nation->update([
            'project_bits' => (string) PWHelperService::PROJECTS['Urban Planning'],
        ]);
        $grant = $this->createCityGrant($nation->num_cities + 1);
        $grant->update([
            'requirements' => ['required_projects' => ['Urban Planning']],
        ]);

        $this->actingAs($user)
            ->post(route('grants.city.request'), ['account_id' => $account->id])
            ->assertRedirect(route('grants.city'))
            ->assertSessionHas('alert-type', 'success');

        $request = CityGrantRequest::query()->sole();
        $nation->update(['project_bits' => '0']);
        $admin = $this->createAdminWithPermission('manage-city-grants');

        $this->actingAs($admin)
            ->from(route('admin.grants.city'))
            ->post(route('admin.grants.city.approve', ['CityGrantRequest' => $request->id]))
            ->assertRedirect(route('admin.grants.city'))
            ->assertSessionHas('alert-type', 'error')
            ->assertSessionHas(
                'alert-message',
                'You must have all of these projects: Urban Planning.',
            );

        $request->refresh();
        $account->refresh();

        $this->assertSame('pending', $request->status);
        $this->assertSame(1, $request->pending_key);
        $this->assertSame(0.0, (float) $account->money);
    }

    public function test_member_cannot_request_a_city_grant_when_custom_nested_requirements_fail(): void
    {
        [$user, $nation, $account] = $this->createMemberWithAccount();
        $grant = $this->createCityGrant($nation->num_cities + 1);
        $grant->update([
            'requirements' => [
                'group' => 'all',
                'rules' => [[
                    'group' => 'any',
                    'rules' => [
                        [
                            'field' => 'num_cities',
                            'operator' => 'gte',
                            'value' => 10,
                            'message' => '',
                        ],
                        [
                            'field' => 'color',
                            'operator' => 'eq',
                            'value' => 'RED',
                            'message' => '',
                        ],
                    ],
                ]],
            ],
        ]);

        $this->actingAs($user)
            ->post(route('grants.city.request'), ['account_id' => $account->id])
            ->assertRedirect(route('grants.city'))
            ->assertSessionHas('alert-type', 'error')
            ->assertSessionHas(
                'alert-message',
                'You are not currently eligible for this city grant. Review the eligibility requirements shown on this page, correct any unmet items, and try again.',
            );

        $this->assertDatabaseCount('city_grant_requests', 0);
    }

    public function test_city_grant_custom_failure_message_is_not_exposed_during_submission(): void
    {
        [$user, $nation, $account] = $this->createMemberWithAccount();
        $grant = $this->createCityGrant($nation->num_cities + 1);
        $grant->update([
            'requirements' => [
                'group' => 'all',
                'rules' => [[
                    'field' => 'num_cities',
                    'operator' => 'gte',
                    'value' => 10,
                    'message' => 'Reach city 10 before requesting this tier.',
                ]],
            ],
        ]);

        $this->actingAs($user)
            ->post(route('grants.city.request'), ['account_id' => $account->id])
            ->assertRedirect(route('grants.city'))
            ->assertSessionHas(
                'alert-message',
                'You are not currently eligible for this city grant. Review the eligibility requirements shown on this page, correct any unmet items, and try again.',
            );
    }

    public function test_member_city_grant_page_shows_requirement_summary_and_failures(): void
    {
        [$user, $nation] = $this->createMemberWithAccount();
        $grant = $this->createCityGrant($nation->num_cities + 1);
        $grant->update([
            'requirements' => [
                'group' => 'all',
                'rules' => [[
                    'field' => 'num_cities',
                    'operator' => 'gte',
                    'value' => 10,
                    'message' => 'Reach city 10 before requesting this tier.',
                ]],
            ],
        ]);

        $this->actingAs($user)
            ->get(route('grants.city'))
            ->assertOk()
            ->assertSee('City count &gt;= 10', false)
            ->assertSee('Reach city 10 before requesting this tier.')
            ->assertSee('Requirements not met');
    }

    public function test_admin_city_grant_table_shows_full_requirement_summaries(): void
    {
        $grant = $this->createCityGrant(6);
        $grant->update([
            'requirements' => [
                'group' => 'all',
                'rules' => [[
                    'field' => 'color',
                    'operator' => 'eq',
                    'value' => 'BLUE',
                    'message' => '',
                ]],
            ],
        ]);
        $admin = $this->createAdminWithPermission('manage-city-grants');
        $admin = $this->grantPermissions($admin, ['view-city-grants']);

        $this->actingAs($admin)
            ->get(route('admin.grants.city'))
            ->assertOk()
            ->assertSee('Color is Blue');
    }

    public function test_malformed_city_grant_requirements_fail_closed(): void
    {
        [$user, $nation, $account] = $this->createMemberWithAccount();
        $grant = $this->createCityGrant($nation->num_cities + 1);
        $grant->requirements = '__invalid_requirements__';
        $grant->save();

        $this->actingAs($user)
            ->post(route('grants.city.request'), ['account_id' => $account->id])
            ->assertRedirect(route('grants.city'))
            ->assertSessionHas(
                'alert-message',
                'You are not currently eligible for this city grant. Review the eligibility requirements shown on this page, correct any unmet items, and try again.',
            );

        $this->assertDatabaseCount('city_grant_requests', 0);
    }

    public function test_admin_can_create_and_update_city_grant_requirement_trees(): void
    {
        $admin = $this->createAdminWithPermission('manage-city-grants');
        $initialRequirements = [
            'group' => 'all',
            'rules' => [[
                'field' => 'num_cities',
                'operator' => 'gte',
                'value' => 5,
                'message' => '',
            ]],
        ];

        $this->actingAs($admin)
            ->post(route('admin.grants.city.create'), [
                'city_number' => 6,
                'grant_amount' => 100,
                'enabled' => '1',
                'description' => 'Growth support',
                'requirements_json' => json_encode($initialRequirements, JSON_THROW_ON_ERROR),
            ])
            ->assertRedirect(route('admin.grants.city'))
            ->assertSessionHas('alert-type', 'success');

        $grant = CityGrant::query()->sole();
        $this->assertSame($initialRequirements, $grant->requirements);

        $updatedRequirements = [
            'group' => 'any',
            'rules' => [[
                'field' => 'color',
                'operator' => 'eq',
                'value' => 'BLUE',
                'message' => 'Move to blue before applying.',
            ]],
        ];

        $this->actingAs($admin)
            ->post(route('admin.grants.city.update', $grant), [
                'city_number' => 6,
                'grant_amount' => 90,
                'enabled' => '1',
                'description' => 'Updated support',
                'requirements_json' => json_encode($updatedRequirements, JSON_THROW_ON_ERROR),
            ])
            ->assertRedirect(route('admin.grants.city'))
            ->assertSessionHas('alert-type', 'success');

        $this->assertSame($updatedRequirements, $grant->fresh()->requirements);
    }

    public function test_admin_city_grant_form_rejects_invalid_requirement_json(): void
    {
        $admin = $this->createAdminWithPermission('manage-city-grants');

        $this->actingAs($admin)
            ->from(route('admin.grants.city'))
            ->post(route('admin.grants.city.create'), [
                'city_number' => 6,
                'grant_amount' => 100,
                'enabled' => '1',
                'description' => 'Growth support',
                'requirements_json' => '{invalid',
            ])
            ->assertRedirect(route('admin.grants.city'))
            ->assertSessionHasErrors('requirements')
            ->assertSessionHasInput('requirements_json', '{invalid');

        $this->assertDatabaseCount('city_grants', 0);
    }

    public function test_admin_can_deny_a_pending_city_grant_request(): void
    {
        [$user, $nation, $account] = $this->createMemberWithAccount();
        $grant = $this->createCityGrant($nation->num_cities + 1);
        $request = CityGrantRequest::query()->create([
            'city_number' => $grant->city_number,
            'grant_amount' => 320000,
            'nation_id' => $nation->id,
            'account_id' => $account->id,
            'status' => 'pending',
            'pending_key' => 1,
        ]);
        $admin = $this->createAdminWithPermission('manage-city-grants');

        $this->actingAs($admin)
            ->from(route('admin.grants.city'))
            ->post(route('admin.grants.city.deny', ['CityGrantRequest' => $request->id]))
            ->assertRedirect(route('admin.grants.city'))
            ->assertSessionHas('alert-type', 'success');

        $request->refresh();

        $this->assertSame('denied', $request->status);
        $this->assertNull($request->pending_key);
        $this->assertNotNull($request->denied_at);

        Notification::assertSentTo(
            $nation,
            CityGrantNotification::class,
            fn (CityGrantNotification $notification): bool => $notification->status === 'denied'
                && $notification->request->is($request)
        );
    }

    public function test_admin_cannot_approve_their_own_city_grant_request(): void
    {
        [$admin, $nation, $account] = $this->createMemberWithAccount(777699, admin: true);
        $admin = $this->grantPermissions($admin, ['manage-city-grants']);
        $grant = $this->createCityGrant($nation->num_cities + 1);
        $request = CityGrantRequest::query()->create([
            'city_number' => $grant->city_number,
            'grant_amount' => 320000,
            'nation_id' => $nation->id,
            'account_id' => $account->id,
            'status' => 'pending',
            'pending_key' => 1,
        ]);

        $this->actingAs($admin)
            ->post(route('admin.grants.city.approve', ['CityGrantRequest' => $request->id]))
            ->assertForbidden();

        $request->refresh();

        $this->assertSame('pending', $request->status);
        $this->assertSame(1, $request->pending_key);
    }

    public function test_member_cannot_request_a_city_grant_already_approved_for_that_city_number(): void
    {
        [$user, $nation, $account] = $this->createMemberWithAccount(777602);
        $grant = $this->createCityGrant($nation->num_cities + 1);

        CityGrantRequest::query()->create([
            'city_number' => $grant->city_number,
            'grant_amount' => 320000,
            'nation_id' => $nation->id,
            'account_id' => $account->id,
            'status' => 'approved',
            'pending_key' => null,
            'approved_at' => now(),
        ]);

        $this->actingAs($user)
            ->from(route('grants.city'))
            ->post(route('grants.city.request'), [
                'account_id' => $account->id,
            ])
            ->assertRedirect(route('grants.city'))
            ->assertSessionHas('alert-type', 'error')
            ->assertSessionHas(
                'alert-message',
                'This city grant is outside the currently available program limits or has already been used. Review the available grant tier shown on this page or contact the economics team.',
            );
    }

    public function test_admin_cannot_approve_a_city_grant_while_global_approvals_are_disabled(): void
    {
        SettingService::setGrantApprovalsEnabled(false);

        [$user, $nation, $account] = $this->createMemberWithAccount(777603);
        $grant = $this->createCityGrant($nation->num_cities + 1);
        $request = CityGrantRequest::query()->create([
            'city_number' => $grant->city_number,
            'grant_amount' => 320000,
            'nation_id' => $nation->id,
            'account_id' => $account->id,
            'status' => 'pending',
            'pending_key' => 1,
        ]);
        $admin = $this->createAdminWithPermission('manage-city-grants');

        $this->actingAs($admin)
            ->from(route('admin.grants.city'))
            ->post(route('admin.grants.city.approve', ['CityGrantRequest' => $request->id]))
            ->assertRedirect(route('admin.grants.city'))
            ->assertSessionHas('alert-type', 'error')
            ->assertSessionHas('alert-message', 'Grant approvals are currently paused.');

        $request->refresh();
        $this->assertSame('pending', $request->status);
    }

    public function test_admin_city_grant_queue_explains_when_approvals_are_disabled(): void
    {
        SettingService::setGrantApprovalsEnabled(false);

        [, $nation, $account] = $this->createMemberWithAccount(777606);
        $grant = $this->createCityGrant($nation->num_cities + 1);
        CityGrantRequest::query()->create([
            'city_number' => $grant->city_number,
            'grant_amount' => 320000,
            'nation_id' => $nation->id,
            'account_id' => $account->id,
            'status' => 'pending',
            'pending_key' => 1,
        ]);
        $admin = $this->createAdminWithPermission('manage-city-grants');
        $admin = $this->grantPermissions($admin, ['view-city-grants']);

        $this->actingAs($admin)
            ->get(route('admin.grants.city'))
            ->assertOk()
            ->assertSee('City grant approvals are paused')
            ->assertSee('Approval paused')
            ->assertSee('Manual city grant disbursements are unavailable while grant approvals are paused.')
            ->assertDontSee('>Approve and deposit<', false);
    }

    public function test_paused_manual_city_grant_attempt_does_not_leave_a_pending_request(): void
    {
        SettingService::setGrantApprovalsEnabled(false);

        [, $nation, $account] = $this->createMemberWithAccount(777607);
        $grant = $this->createCityGrant($nation->num_cities + 1);
        $admin = $this->createAdminWithPermission('manage-city-grants');

        $this->actingAs($admin)
            ->from(route('admin.grants.city'))
            ->post(route('admin.manual-disbursements.city-grants'), [
                'city_grant_id' => $grant->id,
                'nation_id' => $nation->id,
                'account_id' => $account->id,
                'idempotency_key' => (string) Str::uuid(),
            ])
            ->assertRedirect(route('admin.grants.city'))
            ->assertSessionHas('alert-type', 'error')
            ->assertSessionHas('alert-message', 'Grant approvals are currently paused.');

        $this->assertDatabaseCount('city_grant_requests', 0);
        $this->assertSame(0.0, (float) $account->fresh()->money);
    }

    public function test_city_grant_approval_denies_when_account_ownership_does_not_match(): void
    {
        [$user, $nation, $account] = $this->createMemberWithAccount(777604);
        [, , $foreignAccount] = $this->createMemberWithAccount(777605);
        $grant = $this->createCityGrant($nation->num_cities + 1);
        $request = CityGrantRequest::query()->create([
            'city_number' => $grant->city_number,
            'grant_amount' => 320000,
            'nation_id' => $nation->id,
            'account_id' => $foreignAccount->id,
            'status' => 'pending',
            'pending_key' => 1,
        ]);
        $admin = $this->createAdminWithPermission('manage-city-grants');

        $this->actingAs($admin)
            ->from(route('admin.grants.city'))
            ->post(route('admin.grants.city.approve', ['CityGrantRequest' => $request->id]))
            ->assertRedirect(route('admin.grants.city'))
            ->assertSessionHas('alert-type', 'success');

        $request->refresh();
        $account->refresh();
        $foreignAccount->refresh();

        $this->assertSame('denied', $request->status);
        $this->assertNull($request->pending_key);
        $this->assertNotNull($request->denied_at);
        $this->assertSame(0.0, (float) $account->money);
        $this->assertSame(0.0, (float) $foreignAccount->money);
    }

    public function test_city_grant_approval_fails_when_the_grant_is_missing_or_disabled(): void
    {
        [$user, $nation, $account] = $this->createMemberWithAccount(777606);
        $grant = $this->createCityGrant($nation->num_cities + 1);
        $request = CityGrantRequest::query()->create([
            'city_number' => $grant->city_number,
            'grant_amount' => 320000,
            'nation_id' => $nation->id,
            'account_id' => $account->id,
            'status' => 'pending',
            'pending_key' => 1,
        ]);
        $grant->delete();
        $admin = $this->createAdminWithPermission('manage-city-grants');

        $this->actingAs($admin)
            ->from(route('admin.grants.city'))
            ->post(route('admin.grants.city.approve', ['CityGrantRequest' => $request->id]))
            ->assertRedirect(route('admin.grants.city'))
            ->assertSessionHas('alert-type', 'error')
            ->assertSessionHas('alert-message', 'This city grant is currently disabled.');

        $request->refresh();
        $this->assertSame('pending', $request->status);
    }

    /**
     * @return array{0: User, 1: Nation, 2: Account}
     */
    private function createMemberWithAccount(int $nationId = 777601, bool $admin = false): array
    {
        $nation = Nation::factory()->create([
            'id' => $nationId,
            'alliance_id' => 777,
            'alliance_position' => 'MEMBER',
            'alliance_position_id' => 1,
            'num_cities' => 5,
        ]);

        $user = ($admin ? User::factory()->verified()->admin() : User::factory()->verified())->create([
            'nation_id' => $nation->id,
        ]);

        $account = new Account;
        $account->nation_id = $nation->id;
        $account->name = 'Primary';
        $account->save();

        if ($admin) {
            $this->attachDiscordAccount($user);
        }

        return [$user, $nation, $account];
    }

    private function createCityGrant(int $cityNumber): CityGrant
    {
        return CityGrant::query()->create([
            'description' => 'Growth support',
            'enabled' => true,
            'grant_amount' => 100,
            'city_number' => $cityNumber,
            'requirements' => [],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function growthCircleEnrollmentRequirement(): array
    {
        return [
            'group' => 'all',
            'rules' => [[
                'field' => 'growth_circle_enrollment',
                'operator' => 'eq',
                'value' => 'ENROLLED',
                'message' => '',
            ]],
        ];
    }

    private function createAdminWithPermission(string $permission): User
    {
        [$admin] = $this->createMemberWithAccount(777699, admin: true);

        return $this->grantPermissions($admin, [$permission, 'manage-manual-disbursements']);
    }
}
