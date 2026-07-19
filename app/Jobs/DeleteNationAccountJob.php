<?php

namespace App\Jobs;

use App\Models\NationAccount;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class DeleteNationAccountJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable;

    public $timeout = 20;

    public function __construct(public array $accounts) {}

    public function handle(): void
    {
        if (empty($this->accounts)) {
            return;
        }

        try {
            foreach ($this->accounts as $account) {
                if (! isset($account['id'])) {
                    continue;
                }

                NationAccount::query()
                    ->where('nation_id', $account['id'])
                    ->delete();
            }
        } catch (Throwable $exception) {
            Log::error('Failed to delete nation accounts', [
                'nation_ids' => collect($this->accounts)->pluck('id')->filter()->take(10)->values()->all(),
                'record_count' => count($this->accounts),
                'exception_class' => $exception::class,
                'error' => $exception->getMessage(),
                'trace_id' => Str::uuid()->toString(),
            ]);

            throw $exception;
        }
    }
}
