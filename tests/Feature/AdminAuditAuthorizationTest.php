<?php

namespace Tests\Feature;

use App\Enums\AuditPriority;
use App\Enums\AuditTargetType;
use App\Models\AuditRule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
    }

    public function test_manage_audits_permission_can_create_audit_rules(): void
    {
        $admin = $this->createAdmin(['view-audits', 'manage-audits']);

        $this->actingAs($admin)
            ->get(route('admin.audits.rules.create'))
            ->assertOk()
            ->assertSee('New Audit Rule');

        $this->actingAs($admin)
            ->post(route('admin.audits.rules.store'), [
                'name' => 'High score review',
                'target_type' => AuditTargetType::Nation->value,
                'priority' => AuditPriority::Low->value,
                'expression' => 'nation.score > 1000',
                'enabled' => '1',
            ])
            ->assertRedirect(route('admin.audits.rules.index'));

        $this->assertDatabaseHas('audit_rules', [
            'name' => 'High score review',
            'target_type' => AuditTargetType::Nation->value,
            'priority' => AuditPriority::Low->value,
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
        ]);
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
            'target_type' => AuditTargetType::Nation,
            'priority' => AuditPriority::Info,
            'expression' => 'nation.score > 1000',
            'enabled' => true,
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
        ]);
    }
}
