<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTestUsers;
use Tests\TestCase;

class RecruitmentApplicationSettingsVisibilityTest extends TestCase
{
    use BuildsTestUsers;
    use RefreshDatabase;

    public function test_recruitment_page_exposes_automatic_message_controls(): void
    {
        $admin = $this->createAdmin(['view-recruitment', 'manage-recruitment']);

        $response = $this->actingAs($admin)
            ->get(route('admin.recruitment.index'))
            ->assertOk()
            ->assertSee('Automatic outreach paused')
            ->assertSee('Enable automatic recruitment messages')
            ->assertSee('Enable follow-up message');

        $html = $response->getContent();

        $this->assertStringContainsString('for="recruitment_enabled"', $html);
        $this->assertStringContainsString('id="recruitment_enabled"', $html);
        $this->assertStringContainsString('for="follow_up_enabled"', $html);
        $this->assertStringContainsString('id="follow_up_enabled"', $html);
    }

    public function test_application_page_exposes_intake_control_by_default(): void
    {
        $admin = $this->createAdmin(['view-applications', 'manage-applications']);

        $response = $this->actingAs($admin)
            ->get(route('admin.applications.index'))
            ->assertOk()
            ->assertSee('Application intake and Discord settings')
            ->assertSee('Accept new applications');

        $html = $response->getContent();

        $this->assertStringContainsString('for="applications_enabled"', $html);
        $this->assertStringContainsString('id="applications_enabled"', $html);
        $this->assertMatchesRegularExpression(
            '/<details[^>]*id="application-settings"[^>]*\sopen(?:\s|>)/',
            $html,
        );
    }

    public function test_settings_directory_advertises_both_availability_controls(): void
    {
        $admin = $this->createAdmin(['view-applications', 'view-recruitment']);

        $this->actingAs($admin)
            ->get(route('admin.settings'))
            ->assertOk()
            ->assertSee('Open or pause new applications')
            ->assertSee('Enable or pause automatic outreach');
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
}
