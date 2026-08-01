<?php

namespace Tests\Feature;

use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\Concerns\BuildsTestUsers;
use Tests\TestCase;

class LogViewerAuthorizationTest extends TestCase
{
    use BuildsTestUsers;
    use RefreshDatabase;

    public function test_diagnostic_permission_does_not_grant_raw_log_access(): void
    {
        $admin = $this->grantPermissions(
            $this->createVerifiedAdmin(),
            ['view-diagnostic-info'],
        );

        $this->actingAs($admin)
            ->get('/log-viewer')
            ->assertForbidden();
    }

    public function test_raw_log_access_requires_an_admin_with_the_dedicated_permission(): void
    {
        $nonAdmin = $this->grantPermissions(
            $this->createVerifiedUser(),
            ['view-application-logs'],
        );

        $this->actingAs($nonAdmin)
            ->get('/log-viewer')
            ->assertForbidden();

        $admin = $this->grantPermissions(
            $this->createVerifiedAdmin(),
            ['view-application-logs'],
        );

        $this->actingAs($admin)
            ->get('/log-viewer')
            ->assertOk();
    }

    public function test_log_viewer_honors_admin_mfa_policy(): void
    {
        SettingService::setMfaRequiredForAdmins(true);
        $admin = $this->grantPermissions(
            $this->createVerifiedAdmin(),
            ['view-application-logs'],
        );

        $this->actingAs($admin)
            ->getJson('/log-viewer')
            ->assertForbidden();
    }

    public function test_log_deletion_is_disabled_even_for_authorized_admins(): void
    {
        $admin = $this->grantPermissions(
            $this->createVerifiedAdmin(),
            ['view-application-logs'],
        );

        $this->assertTrue(Gate::forUser($admin)->denies('deleteLogFile', new \stdClass));
        $this->assertTrue(Gate::forUser($admin)->denies('deleteLogFolder', new \stdClass));
    }
}
