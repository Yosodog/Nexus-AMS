<?php

namespace Tests\Feature;

use App\Enums\AuditPriority;
use App\Enums\AuditTargetType;
use App\Jobs\RunAuditRuleJob;
use App\Jobs\SendAuditRemindersJob;
use App\Models\AuditResult;
use App\Models\AuditRule;
use App\Models\Nation;
use App\Services\AllianceMemberEligibilityService;
use App\Services\Discord\PrivateNotificationService;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Mockery;
use Tests\TestCase;

class AuditReminderScheduleTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_full_audits_remain_hourly_and_reminders_remain_one_daily_digest(): void
    {
        $events = collect(app(Schedule::class)->events());
        $fullAuditEvents = $events->filter(fn (Event $event): bool => is_string($event->command)
            && str_contains($event->command, 'audits:run'));

        $this->assertCount(1, $fullAuditEvents);

        /** @var Event $fullAuditEvent */
        $fullAuditEvent = $fullAuditEvents->first();
        $this->assertSame('30 * * * *', $fullAuditEvent->expression);
        $this->assertTrue($fullAuditEvent->runInBackground);
        $this->assertTrue($fullAuditEvent->withoutOverlapping);
        $this->assertSame(90, $fullAuditEvent->expiresAt);
        $this->assertTrue($fullAuditEvent->onOneServer);

        $reminderEvents = $events->filter(
            fn (Event $event): bool => $event->description === SendAuditRemindersJob::class,
        );

        $this->assertCount(1, $reminderEvents);

        /** @var Event $reminderEvent */
        $reminderEvent = $reminderEvents->first();
        $this->assertInstanceOf(CallbackEvent::class, $reminderEvent);
        $this->assertSame('0 18 * * *', $reminderEvent->expression);
        $this->assertTrue($reminderEvent->withoutOverlapping);
        $this->assertSame(60, $reminderEvent->expiresAt);
        $this->assertTrue($reminderEvent->onOneServer);

        $this->assertCount(0, $events->filter(
            fn (Event $event): bool => $event->description === RunAuditRuleJob::class,
        ), 'Per-rule evaluations must not create immediate or per-rule reminder schedules.');
    }

    public function test_daily_digest_groups_current_findings_and_excludes_snoozed_and_waived_findings(): void
    {
        Carbon::setTestNow('2026-08-02 18:00:00');
        $nation = Nation::factory()->create();

        $this->createFinding($nation, dueAt: now()->subHour());
        $this->createFinding($nation, dueAt: now()->addDay());
        $this->createFinding($nation, snoozedUntil: now()->addDay());
        $this->createFinding($nation, waivedUntil: now()->addDay());

        $eligibility = Mockery::mock(AllianceMemberEligibilityService::class);
        $eligibility->shouldReceive('isEligibleNation')
            ->once()
            ->with(Mockery::on(fn (Nation $candidate): bool => $candidate->is($nation)))
            ->andReturnTrue();

        $notifications = Mockery::mock(PrivateNotificationService::class);
        $notifications->shouldReceive('enqueueForNation')
            ->once()
            ->withArgs(function (
                Nation $candidate,
                string $category,
                string $eventType,
                string $notificationId,
                array $subject,
                string $deepLinkPath,
                array $summary,
            ) use ($nation): bool {
                $this->assertTrue($candidate->is($nation));
                $this->assertSame('audits', $category);
                $this->assertSame('audit_summary_reminder', $eventType);
                $this->assertSame("audit-reminder:{$nation->id}:2026-08-02", $notificationId);
                $this->assertSame([
                    'type' => 'audit_summary',
                    'id' => $nation->id,
                    'label' => 'Audit findings',
                ], $subject);
                $this->assertSame('/audit', $deepLinkPath);
                $this->assertSame([
                    'finding_count' => 2,
                    'overdue_count' => 1,
                ], $summary);

                return true;
            })
            ->andReturnTrue();

        (new SendAuditRemindersJob)->handle($notifications, $eligibility);
    }

    private function createFinding(
        Nation $nation,
        ?Carbon $dueAt = null,
        ?Carbon $snoozedUntil = null,
        ?Carbon $waivedUntil = null,
    ): AuditResult {
        $rule = AuditRule::query()->create([
            'name' => 'Finding '.$nation->id.' '.AuditRule::query()->count(),
            'target_type' => AuditTargetType::Nation,
            'priority' => AuditPriority::Medium,
            'definition' => [
                'schema_version' => 1,
                'criteria' => [
                    'group' => 'all',
                    'rules' => [[
                        'id' => fake()->uuid(),
                        'field' => 'nation.score',
                        'operator' => 'lt',
                        'value' => 1000,
                    ]],
                ],
                'exceptions' => [
                    'group' => 'any',
                    'rules' => [],
                ],
            ],
            'revision' => 1,
            'enabled' => true,
        ]);

        return AuditResult::query()->create([
            'audit_rule_id' => $rule->id,
            'rule_revision' => 1,
            'target_type' => AuditTargetType::Nation,
            'target_key' => "nation:{$nation->id}",
            'nation_id' => $nation->id,
            'first_detected_at' => now()->subDay(),
            'last_evaluated_at' => now(),
            'due_at' => $dueAt,
            'snoozed_until' => $snoozedUntil,
            'waived_until' => $waivedUntil,
        ]);
    }
}
