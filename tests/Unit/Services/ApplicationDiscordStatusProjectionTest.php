<?php

namespace Tests\Unit\Services;

use App\Enums\ApplicationStatus;
use App\Enums\DiscordQueueStatus;
use App\Models\Application;
use App\Models\DiscordQueue;
use App\Services\Discord\ApplicationDiscordStatusProjection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApplicationDiscordStatusProjectionTest extends TestCase
{
    use RefreshDatabase;

    private ApplicationDiscordStatusProjection $projection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->projection = app(ApplicationDiscordStatusProjection::class);
    }

    public function test_legacy_pending_application_explains_unconfirmed_discord_setup(): void
    {
        $application = $this->application(ApplicationStatus::Pending);
        $status = $this->projection->forMember($application);

        $this->assertSame('unknown', $status['channel_health']['state']);
        $this->assertSame('not_requested', $status['reconciliation']['state']);
        $this->assertSame('discord_setup_unconfirmed', $status['progress']['blockers'][0]['code']);
        $this->assertSame("/apply?application={$application->id}", $status['progress']['next_action']['deep_link_path']);
        $this->assertFalse($status['progress']['facts'][1]['complete']);
        $this->assertFalse($status['progress']['facts'][2]['complete']);
    }

    public function test_queued_pending_application_reports_preparing_without_claiming_success(): void
    {
        [$application, $queue] = $this->applicationWithQueue(
            ApplicationStatus::Pending,
            DiscordQueueStatus::Pending,
        );

        $status = $this->projection->forMember($application, $queue);

        $this->assertSame('preparing', $status['channel_health']['state']);
        $this->assertSame('queued', $status['reconciliation']['state']);
        $this->assertSame([], $status['progress']['blockers']);
        $this->assertFalse($status['progress']['facts'][3]['complete']);
    }

    public function test_completed_pending_reconciliation_reports_the_verified_channel(): void
    {
        [$application, $queue] = $this->applicationWithQueue(
            ApplicationStatus::Pending,
            DiscordQueueStatus::Complete,
            ['discord_channel_id' => '123456789012345678'],
        );

        $status = $this->projection->forMember($application, $queue);

        $this->assertSame([
            'state' => 'ready',
            'label' => 'Private interview channel is ready.',
            'channel_id' => '123456789012345678',
        ], $status['channel_health']);
        $this->assertTrue($status['progress']['facts'][1]['complete']);
        $this->assertTrue($status['progress']['facts'][3]['complete']);
    }

    public function test_failed_reconciliation_returns_one_member_safe_blocker_without_internal_issue_keys(): void
    {
        [$application, $queue] = $this->applicationWithQueue(
            ApplicationStatus::Denied,
            DiscordQueueStatus::Failed,
            [
                'discord_channel_id' => 'invalid-internal-channel',
                'discord_reconcile_issues' => [
                    'interviewer_role_not_configured',
                    'approval_announcement_template_invalid',
                ],
            ],
        );

        $status = $this->projection->forMember($application, $queue);
        $encoded = json_encode($status, JSON_THROW_ON_ERROR);

        $this->assertSame('attention', $status['channel_health']['state']);
        $this->assertSame('attention', $status['reconciliation']['state']);
        $this->assertSame(2, $status['reconciliation']['issues_count']);
        $this->assertCount(1, $status['progress']['blockers']);
        $this->assertStringNotContainsString('interviewer_role_not_configured', $encoded);
        $this->assertStringNotContainsString('approval_announcement_template_invalid', $encoded);
    }

    public function test_completed_terminal_reconciliation_closes_the_channel_stage(): void
    {
        [$application, $queue] = $this->applicationWithQueue(
            ApplicationStatus::Approved,
            DiscordQueueStatus::Complete,
            ['discord_channel_id' => '123456789012345678'],
        );

        $status = $this->projection->forMember($application, $queue);

        $this->assertSame('not_required', $status['channel_health']['state']);
        $this->assertArrayNotHasKey('channel_id', $status['channel_health']);
        $this->assertTrue($status['progress']['facts'][1]['complete']);
        $this->assertTrue($status['progress']['facts'][2]['complete']);
        $this->assertSame('/user/dashboard', $status['progress']['next_action']['deep_link_path']);
    }

    public function test_foreign_queue_action_is_never_used_as_application_reconciliation_evidence(): void
    {
        [$application, $queue] = $this->applicationWithQueue(
            ApplicationStatus::Pending,
            DiscordQueueStatus::Complete,
        );
        $queue->forceFill(['action' => 'PRIVATE_NOTIFICATION'])->save();

        $status = $this->projection->forMember($application->fresh(), $queue->fresh());

        $this->assertSame('attention', $status['reconciliation']['state']);
        $this->assertSame('attention', $status['channel_health']['state']);
    }

    /** @param array<string, mixed> $overrides */
    private function application(ApplicationStatus $status, array $overrides = []): Application
    {
        return Application::query()->create(array_merge([
            'nation_id' => 9001,
            'leader_name_snapshot' => 'Example Leader',
            'discord_user_id' => '223456789012345678',
            'discord_username' => 'example-user',
            'status' => $status,
            'pending_key' => $status === ApplicationStatus::Pending ? 1 : null,
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array{Application, DiscordQueue}
     */
    private function applicationWithQueue(
        ApplicationStatus $applicationStatus,
        DiscordQueueStatus $queueStatus,
        array $overrides = [],
    ): array {
        $application = $this->application($applicationStatus, array_merge([
            'discord_reconcile_revision' => 1,
        ], $overrides));
        $queue = DiscordQueue::query()->create([
            'action' => ApplicationDiscordStatusProjection::ACTION,
            'payload' => ['contract_version' => 1],
            'status' => $queueStatus,
        ]);
        $application->forceFill(['discord_reconcile_queue_id' => $queue->id])->save();

        return [$application->fresh(), $queue->fresh()];
    }
}
