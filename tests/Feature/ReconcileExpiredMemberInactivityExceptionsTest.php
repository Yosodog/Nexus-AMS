<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\MemberInactivityException;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReconcileExpiredMemberInactivityExceptionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_expiry_reconciliation_is_idempotent_audited_and_preserves_history(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-06 12:00:00 UTC'));
        $expired = MemberInactivityException::factory()->create([
            'starts_at' => now()->subDays(2),
            'ends_at' => now()->subHour(),
            'expired_at' => null,
        ]);
        $alreadyReconciled = MemberInactivityException::factory()->create([
            'starts_at' => now()->subDays(3),
            'ends_at' => now()->subDays(2),
            'expired_at' => now()->subDay(),
        ]);
        $revoked = MemberInactivityException::factory()->create([
            'starts_at' => now()->subDays(2),
            'ends_at' => now()->subHour(),
            'revoked_at' => now()->subHours(2),
        ]);
        $active = MemberInactivityException::factory()->create([
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHour(),
        ]);
        $originalEnd = $expired->ends_at->toIso8601String();

        $this->artisan('members:reconcile-inactivity-exceptions')
            ->expectsOutput('Reconciled 1 expired member inactivity exception(s).')
            ->assertSuccessful();
        $this->artisan('members:reconcile-inactivity-exceptions')
            ->expectsOutput('Reconciled 0 expired member inactivity exception(s).')
            ->assertSuccessful();

        $this->assertNotNull($expired->fresh()->expired_at);
        $this->assertSame($originalEnd, $expired->fresh()->ends_at->toIso8601String());
        $this->assertNotNull($alreadyReconciled->fresh()->expired_at);
        $this->assertNull($revoked->fresh()->expired_at);
        $this->assertNull($active->fresh()->expired_at);
        $this->assertSame(1, AuditLog::query()->where('action', 'member_inactivity_exception_expired')->count());
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'member_inactivity_exception_expired',
            'actor_type' => 'scheduler',
            'subject_id' => $expired->id,
        ]);
    }

    public function test_expiry_reconciliation_schedule_is_named_locked_and_single_server(): void
    {
        /** @var Event|null $event */
        $event = collect(app(Schedule::class)->events())
            ->first(fn (Event $event): bool => is_string($event->command)
                && str_contains($event->command, 'members:reconcile-inactivity-exceptions'));

        $this->assertNotNull($event);
        $this->assertSame('member-inactivity-exceptions:reconcile-expiry', $event->description);
        $this->assertSame('*/5 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertTrue($event->onOneServer);
    }
}
