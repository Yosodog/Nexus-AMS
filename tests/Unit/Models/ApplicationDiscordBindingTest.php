<?php

namespace Tests\Unit\Models;

use App\Enums\ApplicationStatus;
use App\Models\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApplicationDiscordBindingTest extends TestCase
{
    use RefreshDatabase;

    public function test_application_can_retain_an_explicit_discord_installation_binding(): void
    {
        $application = Application::query()->create([
            'nation_id' => 9001,
            'leader_name_snapshot' => 'Example Leader',
            'discord_user_id' => '123456789012345678',
            'discord_username' => 'example-user',
            'discord_connection_id' => '123e4567-e89b-42d3-a456-426614174000',
            'discord_connection_generation' => 7,
            'discord_application_id' => '223456789012345678',
            'discord_guild_id' => '323456789012345678',
            'status' => ApplicationStatus::Pending,
            'pending_key' => 1,
        ])->fresh();

        $this->assertSame('123e4567-e89b-42d3-a456-426614174000', $application->discord_connection_id);
        $this->assertSame(7, $application->discord_connection_generation);
        $this->assertSame('223456789012345678', $application->discord_application_id);
        $this->assertSame('323456789012345678', $application->discord_guild_id);
    }

    public function test_legacy_application_binding_remains_nullable(): void
    {
        $application = Application::query()->create([
            'nation_id' => 9002,
            'leader_name_snapshot' => 'Legacy Leader',
            'discord_user_id' => '423456789012345678',
            'discord_username' => 'legacy-user',
            'status' => ApplicationStatus::Pending,
            'pending_key' => 1,
        ])->fresh();

        $this->assertNull($application->discord_connection_id);
        $this->assertNull($application->discord_connection_generation);
        $this->assertNull($application->discord_application_id);
        $this->assertNull($application->discord_guild_id);
    }
}
