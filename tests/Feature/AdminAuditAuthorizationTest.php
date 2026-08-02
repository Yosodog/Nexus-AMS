<?php

namespace Tests\Feature;

use App\Enums\AuditPriority;
use App\Enums\AuditTargetType;
use App\Models\AuditRule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\BuildsTestUsers;
use Tests\TestCase;

class AdminAuditAuthorizationTest extends TestCase
{
    use BuildsTestUsers;
    use RefreshDatabase;

    public function test_admin_without_audit_permission_cannot_view_audit_pages(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin)
            ->get(route('admin.audits.index'))
            ->assertForbidden();
    }

    public function test_view_audits_permission_can_read_but_not_manage_audits(): void
    {
        $admin = $this->createAdmin(['view-audits']);
        $rule = $this->createAuditRule($admin);

        $this->actingAs($admin)
            ->get(route('admin.audits.index'))
            ->assertOk()
            ->assertSee('Audit Overview')
            ->assertSee('Rules')
            ->assertDontSee('Run audits')
            ->assertDontSee('Notify members')
            ->assertDontSee('New rule');

        $this->actingAs($admin)
            ->get(route('admin.audits.rules.index'))
            ->assertOk()
            ->assertSee($rule->name)
            ->assertDontSee('New Rule');

        $this->actingAs($admin)
            ->get(route('admin.audits.rules.create'))
            ->assertForbidden();

        $this->actingAs($admin)
            ->post(route('admin.audits.run'))
            ->assertForbidden();

        $this->actingAs($admin)
            ->postJson(route('admin.audits.rules.preview'), [
                'target_type' => AuditTargetType::Nation->value,
                'definition' => $this->definition(),
            ])
            ->assertForbidden();
    }

    public function test_manage_audits_permission_can_create_audit_rules(): void
    {
        $admin = $this->createAdmin(['view-audits', 'manage-audits']);

        $this->actingAs($admin)
            ->get(route('admin.audits.rules.create'))
            ->assertOk()
            ->assertSee('New Audit Rule')
            ->assertDontSee('NEL');

        $preview = $this->actingAs($admin)
            ->postJson(route('admin.audits.rules.preview'), [
                'target_type' => AuditTargetType::Nation->value,
                'definition' => $this->definition(),
            ])
            ->assertOk()
            ->assertJsonPath('plain_language_summary', 'Alert when all of: Score is greater than 1,000 score.')
            ->assertJsonPath('match_count', 0)
            ->assertJsonStructure([
                'definition',
                'plain_language_summary',
                'match_count',
                'samples',
                'warnings',
                'evaluation_time_ms',
                'definition_fingerprint',
                'confirmation_token',
            ]);

        $this->actingAs($admin)
            ->post(route('admin.audits.rules.store'), [
                'name' => 'High score review',
                'description' => 'Review nations over the score threshold.',
                'remediation_guidance' => 'Reduce score or request an audit waiver.',
                'admin_notes' => 'Created by the authorization feature test.',
                'target_type' => AuditTargetType::Nation->value,
                'priority' => AuditPriority::Low->value,
                'definition' => $this->definition(),
                'enabled' => '1',
                'impact_confirmation_token' => $preview->json('confirmation_token'),
            ])
            ->assertRedirect(route('admin.audits.rules.index'));

        $this->assertDatabaseHas('audit_rules', [
            'name' => 'High score review',
            'target_type' => AuditTargetType::Nation->value,
            'priority' => AuditPriority::Low->value,
            'revision' => 1,
            'remediation_guidance' => 'Reduce score or request an audit waiver.',
            'admin_notes' => 'Created by the authorization feature test.',
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
        ]);

        $rule = AuditRule::query()->where('name', 'High score review')->firstOrFail();

        $this->assertSame($this->definition(), $rule->definition);
    }

    public function test_nel_route_and_navigation_reference_are_removed(): void
    {
        $admin = $this->createAdmin(['view-audits', 'manage-audits']);

        $this->assertFalse(Route::has('admin.nel.docs'));

        $this->actingAs($admin)
            ->get(route('admin.audits.index'))
            ->assertOk()
            ->assertDontSee('NEL reference')
            ->assertDontSee('NEL');

        $this->actingAs($admin)
            ->get(route('admin.audits.rules.index'))
            ->assertOk()
            ->assertDontSee('NEL');
    }

    /**
     * @param  array<int, string>  $permissions
     */
    private function createAdmin(array $permissions = []): User
    {
        $admin = $this->createVerifiedAdmin();
        $this->attachDiscordAccount($admin);

        if ($permissions === []) {
            return $admin;
        }

        return $this->grantPermissions($admin, $permissions);
    }

    private function createAuditRule(User $admin): AuditRule
    {
        return AuditRule::query()->create([
            'name' => 'Score threshold',
            'description' => 'Review nations over the score threshold.',
            'remediation_guidance' => 'Reduce score or request an audit waiver.',
            'admin_notes' => 'Authorization fixture.',
            'target_type' => AuditTargetType::Nation,
            'priority' => AuditPriority::Info,
            'definition' => $this->definition(),
            'revision' => 1,
            'enabled' => true,
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
        ]);
    }

    /** @return array<string, mixed> */
    private function definition(): array
    {
        return [
            'schema_version' => 1,
            'criteria' => [
                'group' => 'all',
                'rules' => [[
                    'id' => '511f1001-2106-4542-aa4d-67ee37df35e1',
                    'field' => 'nation.score',
                    'operator' => 'gt',
                    'value' => 1000,
                ]],
            ],
            'exceptions' => [
                'group' => 'any',
                'rules' => [],
            ],
        ];
    }
}
