<?php

namespace App\Jobs;

use App\Models\NationAccount;
use Exception;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class DeleteNationAccountJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable;

    public $timeout = 5;

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
        } catch (Exception $exception) {
            Log::error('Failed to delete nation accounts', [
                'error' => $exception->getMessage(),
                'trace_id' => Str::uuid()->toString(),
            ]);
        }
    }
}
