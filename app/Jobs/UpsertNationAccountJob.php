<?php

namespace App\Jobs;

use App\Models\NationAccount;
use Exception;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class UpsertNationAccountJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable;

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

                NationAccount::upsertFromEvent($account);
            }
        } catch (Exception $exception) {
            Log::error('Failed to upsert nation accounts', [
                'error' => $exception->getMessage(),
                'trace_id' => Str::uuid()->toString(),
            ]);
        }
    }
}
