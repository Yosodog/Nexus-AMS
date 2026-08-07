<?php

namespace App\Jobs;

use App\Models\Account;
use App\Models\AccountStatementExport;
use App\Services\AuditLogger;
use App\Services\Finance\MemberAccountStatementService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class GenerateAccountStatementExport implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 900;

    public int $uniqueFor = 1800;

    public function __construct(public readonly int $exportId) {}

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping($this->uniqueId()))
                ->expireAfter($this->timeout + 60)
                ->dontRelease(),
        ];
    }

    public function uniqueId(): string
    {
        return "account-statement-export:{$this->exportId}";
    }

    public function handle(MemberAccountStatementService $statements, AuditLogger $auditLogger): void
    {
        $export = AccountStatementExport::query()->find($this->exportId);

        if ($export === null || $export->status === AccountStatementExport::STATUS_EXPIRED) {
            return;
        }

        if ($export->isAvailable() && Storage::disk('local')->exists((string) $export->path)) {
            return;
        }

        if ($export->status === AccountStatementExport::STATUS_FAILED) {
            return;
        }

        DB::transaction(function (): void {
            $locked = AccountStatementExport::query()->lockForUpdate()->find($this->exportId);

            if ($locked === null
                || in_array($locked->status, [
                    AccountStatementExport::STATUS_COMPLETED,
                    AccountStatementExport::STATUS_FAILED,
                    AccountStatementExport::STATUS_EXPIRED,
                ], true)) {
                return;
            }

            $locked->forceFill([
                'status' => AccountStatementExport::STATUS_PROCESSING,
                'started_at' => $locked->started_at ?? now(),
                'failure_message' => null,
                'failed_at' => null,
            ])->save();
        });

        $export->refresh();

        if ($export->status !== AccountStatementExport::STATUS_PROCESSING) {
            return;
        }

        $account = Account::query()
            ->withTrashed()
            ->whereKey($export->account_id)
            ->whereHas('user', fn ($query) => $query->whereKey($export->user_id))
            ->first();

        if ($account === null) {
            throw new RuntimeException('The statement account is no longer owned by the requesting user.');
        }

        $disk = Storage::disk('local');
        $directory = "account-statements/{$export->user_id}";
        $temporaryPath = "{$directory}/{$export->public_id}.csv.tmp";
        $finalPath = "{$directory}/{$export->public_id}.csv";
        $stream = fopen('php://temp/maxmemory:5242880', 'w+b');

        if ($stream === false) {
            throw new RuntimeException('Unable to open a temporary stream for the statement export.');
        }

        try {
            $rowCount = $statements->writeCsv($stream, $account, $export->filters);
            rewind($stream);
            $disk->delete([$temporaryPath, $finalPath]);

            if (! $disk->writeStream($temporaryPath, $stream)) {
                throw new RuntimeException('Unable to write the statement export to private storage.');
            }

            if (! $disk->move($temporaryPath, $finalPath)) {
                throw new RuntimeException('Unable to finalize the statement export.');
            }
        } finally {
            fclose($stream);
        }

        $availabilityHours = max(1, (int) config('finance.account_statements.availability_hours', 24));

        $completed = DB::transaction(function () use ($finalPath, $rowCount, $availabilityHours): bool {
            $locked = AccountStatementExport::query()->lockForUpdate()->findOrFail($this->exportId);

            if ($locked->status !== AccountStatementExport::STATUS_PROCESSING) {
                Storage::disk('local')->delete($finalPath);

                return false;
            }

            $locked->forceFill([
                'status' => AccountStatementExport::STATUS_COMPLETED,
                'path' => $finalPath,
                'row_count' => $rowCount,
                'completed_at' => now(),
                'expires_at' => now()->addHours($availabilityHours),
                'failure_message' => null,
            ])->save();

            return true;
        });

        if (! $completed) {
            return;
        }

        try {
            $auditLogger->record(
                category: 'finance',
                action: 'account_statement_export_completed',
                subject: $export->fresh(),
                context: [
                    'account_id' => $export->account_id,
                    'row_count' => $rowCount,
                ],
                message: 'Queued account statement export completed.',
                actorOverride: [
                    'type' => 'system',
                    'name' => 'Account statement export worker',
                ],
            );
        } catch (Throwable $exception) {
            Log::warning('Unable to record account statement export completion audit.', [
                'export_id' => $this->exportId,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $export = AccountStatementExport::query()->find($this->exportId);

        if ($export === null || in_array($export->status, [
            AccountStatementExport::STATUS_COMPLETED,
            AccountStatementExport::STATUS_EXPIRED,
        ], true)) {
            return;
        }

        Storage::disk('local')->delete([
            "account-statements/{$export->user_id}/{$export->public_id}.csv.tmp",
            "account-statements/{$export->user_id}/{$export->public_id}.csv",
        ]);

        $export->forceFill([
            'status' => AccountStatementExport::STATUS_FAILED,
            'path' => null,
            'failure_message' => 'The export could not be prepared. Try again or narrow the date range.',
            'failed_at' => now(),
        ])->save();

        Log::error('Account statement export failed.', [
            'export_id' => $this->exportId,
            'message' => $exception?->getMessage(),
        ]);
    }
}
