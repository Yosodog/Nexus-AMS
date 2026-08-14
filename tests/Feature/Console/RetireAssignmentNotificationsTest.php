<?php

namespace Tests\Feature\Console;

use App\Enums\DiscordQueueStatus;
use App\Models\DiscordNotificationPreference;
use App\Models\DiscordQueue;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RetireAssignmentNotificationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_retirement_is_dry_run_capable_and_refuses_to_mutate_while_an_assignment_dm_is_leased(): void
    {
        $user = User::factory()->create();
        foreach (['war_assignments', 'spy_assignments', 'watchlists'] as $category) {
            DiscordNotificationPreference::query()->create([
                'user_id' => $user->id,
                'category' => $category,
                'enabled' => true,
            ]);
        }

        $pending = $this->assignmentQueue('war_assignment_created', DiscordQueueStatus::Pending);
        $processing = $this->assignmentQueue('spy_assignment_created', DiscordQueueStatus::Processing);
        $unrelated = $this->assignmentQueue('watchlist_triggered', DiscordQueueStatus::Pending);

        $this->artisan('alerts:retire-assignment-notifications', ['--dry-run' => true])
            ->expectsOutputToContain('Would suppress 1 pending assignment notification(s)')
            ->expectsOutputToContain('Active assignment notification leases: 1')
            ->assertSuccessful();

        $this->artisan('alerts:retire-assignment-notifications')
            ->expectsOutputToContain('Pause the Discord worker and wait for active assignment notification leases to finish')
            ->assertFailed();

        $this->assertSame(DiscordQueueStatus::Pending, $pending->refresh()->status);
        $this->assertSame(DiscordQueueStatus::Processing, $processing->refresh()->status);
        $this->assertDatabaseCount('discord_notification_preferences', 3);

        $processing->forceFill(['status' => DiscordQueueStatus::Pending])->save();

        $this->artisan('alerts:retire-assignment-notifications')
            ->expectsOutputToContain('Suppressed 2 pending assignment notification(s)')
            ->assertSuccessful();

        $this->assertSame(DiscordQueueStatus::Complete, $pending->refresh()->status);
        $this->assertSame('feature_retired', $pending->result['reason']);
        $this->assertSame(DiscordQueueStatus::Complete, $processing->refresh()->status);
        $this->assertSame(DiscordQueueStatus::Pending, $unrelated->refresh()->status);
        $this->assertDatabaseMissing('discord_notification_preferences', ['category' => 'war_assignments']);
        $this->assertDatabaseMissing('discord_notification_preferences', ['category' => 'spy_assignments']);
        $this->assertDatabaseHas('discord_notification_preferences', ['category' => 'watchlists']);

        $this->artisan('alerts:retire-assignment-notifications')
            ->expectsOutputToContain('Suppressed 0 pending assignment notification(s)')
            ->assertSuccessful();
    }

    private function assignmentQueue(string $eventType, DiscordQueueStatus $status): DiscordQueue
    {
        return DiscordQueue::query()->create([
            'action' => 'PRIVATE_NOTIFICATION',
            'lane' => 'alerts',
            'connection_id' => '11111111-2222-4333-8444-555555555555',
            'application_id' => '123456789012345678',
            'connection_generation' => 7,
            'guild_id' => '223456789012345678',
            'dedupe_scope' => '11111111-2222-4333-8444-555555555555:7',
            'payload' => [
                'contract_version' => 1,
                'event_type' => $eventType,
            ],
            'status' => $status,
            'attempts' => $status === DiscordQueueStatus::Processing ? 1 : 0,
            'available_at' => now(),
        ]);
    }
}
