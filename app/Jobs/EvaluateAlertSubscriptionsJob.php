<?php

namespace App\Jobs;

use App\Models\AlertSubscription;
use App\Services\Alerts\AlertSubscriptionEvaluator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class EvaluateAlertSubscriptionsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('alert-subscriptions'))->releaseAfter(60)->expireAfter(900)];
    }

    public function handle(AlertSubscriptionEvaluator $evaluator): void
    {
        AlertSubscription::query()
            ->where('is_active', true)
            ->with(['user.discordAccounts', 'user.nation'])
            ->chunkById(100, function ($subscriptions) use ($evaluator): void {
                foreach ($subscriptions as $subscription) {
                    try {
                        $evaluator->evaluate($subscription);
                    } catch (Throwable $exception) {
                        Log::warning('Custom alert evaluation failed.', [
                            'alert_subscription_id' => $subscription->id,
                            'user_id' => $subscription->user_id,
                            'exception' => $exception::class,
                            'message' => $exception->getMessage(),
                        ]);
                    }
                }
            });
    }
}
