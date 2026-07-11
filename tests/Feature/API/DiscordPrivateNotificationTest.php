<?php

namespace Tests\Feature\API;

use App\Enums\DiscordQueueStatus;
use App\Models\DiscordAccount;
use App\Models\DiscordQueue;
use App\Models\Nation;
use App\Models\User;
use App\Services\Discord\PrivateNotificationService;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DiscordPrivateNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_master_switch_preferences_sanitization_dedupe_and_pending_suppression(): void
    {
        $nation = Nation::factory()->create();
        $user = User::factory()->verified()->create(['nation_id' => $nation->id]);
        DiscordAccount::factory()->create([
            'user_id' => $user->id,
            'discord_id' => '234567890123456789',
            'unlinked_at' => null,
        ]);
        $nation->unsetRelation('user');
        $service = app(PrivateNotificationService::class);

        $this->assertFalse($service->enqueueForNation(
            $nation,
            'loans',
            'loan_approved',
            'loan-1-approved',
            ['type' => 'loan', 'id' => 1],
            '/loans',
            ['status' => 'approved'],
        ));
        $this->assertDatabaseCount('discord_queue', 0);

        SettingService::setDiscordPrivateNotificationsEnabled(true);
        $this->assertSame(
            collect(PrivateNotificationService::CATEGORIES)->mapWithKeys(
                fn (string $label, string $category): array => [$category => false]
            )->all(),
            $service->preferencesFor($user),
        );
        $this->assertFalse($service->canSend($user, 'loans'));
        $this->assertFalse($service->enqueueForNation(
            $nation,
            'loans',
            'loan_approved',
            'loan-1-approved',
            ['type' => 'loan', 'id' => 1],
            '/loans',
            ['status' => 'approved'],
        ));

        $service->updatePreferences($user, ['loans' => true, 'grants' => true]);
        $this->assertTrue($service->canSend($user, 'loans'));
        $this->assertTrue($service->enqueueForNation(
            $nation,
            'loans',
            'loan_approved',
            'loan-1-approved',
            ['type' => 'loan', 'id' => 1, 'label' => 'Growth loan'],
            '/loans',
            ['status' => 'approved', 'amount' => '999999.00', 'reason' => 'sensitive'],
        ));
        $this->assertTrue($service->enqueueForNation(
            $nation,
            'loans',
            'loan_approved',
            'loan-1-approved',
            ['type' => 'loan', 'id' => 1],
            '/loans',
            ['status' => 'approved'],
        ));

        $this->assertDatabaseCount('discord_queue', 1);
        $queued = DiscordQueue::query()->firstOrFail();
        $this->assertSame('PRIVATE_NOTIFICATION', $queued->action);
        $this->assertSame('234567890123456789', $queued->payload['recipient_discord_id']);
        $this->assertSame(['status' => 'approved'], $queued->payload['summary']);
        $this->assertArrayNotHasKey('amount', $queued->payload['summary']);
        $this->assertArrayNotHasKey('reason', $queued->payload['summary']);

        $service->updatePreferences($user, ['grants' => true]);
        $this->assertSame(1, $service->suppressPending($user, ['loans']));
        $queued->refresh();
        $this->assertSame(DiscordQueueStatus::Complete, $queued->status);
        $this->assertSame('suppressed', $queued->result['delivery']);
        $this->assertFalse($service->canSend($user, 'loans'));
    }
}
