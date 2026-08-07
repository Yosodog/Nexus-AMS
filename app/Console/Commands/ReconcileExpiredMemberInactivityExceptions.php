<?php

namespace App\Console\Commands;

use App\Models\MemberInactivityException;
use App\Services\AuditLogger;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('members:reconcile-inactivity-exceptions')]
#[Description('Mark ended member inactivity exceptions as expired and audit the transition.')]
class ReconcileExpiredMemberInactivityExceptions extends Command
{
    public function handle(AuditLogger $auditLogger): int
    {
        $now = now();
        $expiredCount = 0;

        MemberInactivityException::query()
            ->whereNull('expired_at')
            ->whereNull('revoked_at')
            ->where('ends_at', '<=', $now)
            ->orderBy('id')
            ->chunkById(100, function ($exceptions) use ($auditLogger, $now, &$expiredCount): void {
                foreach ($exceptions as $exception) {
                    $expired = DB::transaction(function () use ($auditLogger, $exception, $now): ?MemberInactivityException {
                        $locked = MemberInactivityException::query()
                            ->whereKey($exception->getKey())
                            ->whereNull('expired_at')
                            ->whereNull('revoked_at')
                            ->where('ends_at', '<=', $now)
                            ->lockForUpdate()
                            ->first();

                        if (! $locked) {
                            return null;
                        }

                        $locked->forceFill(['expired_at' => $now])->save();
                        $expired = $locked->fresh();
                        $auditLogger->success(
                            category: 'membership',
                            action: 'member_inactivity_exception_expired',
                            subject: $expired,
                            context: ['data' => [
                                'nation_id' => $expired->nation_id,
                                'ended_at' => $expired->ends_at->toIso8601String(),
                                'expired_at' => $expired->expired_at->toIso8601String(),
                            ]],
                            message: 'Member inactivity exception expired automatically.',
                            actorOverride: [
                                'type' => 'scheduler',
                                'name' => 'members:reconcile-inactivity-exceptions',
                            ],
                        );

                        return $expired;
                    });

                    if (! $expired) {
                        continue;
                    }

                    $expiredCount++;
                }
            });

        $this->info("Reconciled {$expiredCount} expired member inactivity exception(s).");

        return self::SUCCESS;
    }
}
