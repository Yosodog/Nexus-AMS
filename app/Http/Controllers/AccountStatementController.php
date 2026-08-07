<?php

namespace App\Http\Controllers;

use App\Http\Requests\AccountStatementRequest;
use App\Http\Requests\StoreAccountStatementExportRequest;
use App\Jobs\GenerateAccountStatementExport;
use App\Models\Account;
use App\Models\AccountStatementExport;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Finance\MemberAccountStatementService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AccountStatementController extends Controller
{
    public function __construct(
        private readonly MemberAccountStatementService $statements,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function index(AccountStatementRequest $request): View|RedirectResponse
    {
        $accounts = $this->ownedAccounts($request->user());

        if ($accounts->isEmpty()) {
            return redirect()->route('accounts.create');
        }

        $account = $this->selectedAccount($accounts, $request->validated('account_id'));
        $filters = $this->statements->normalizeFilters($request->validated());
        $exports = AccountStatementExport::query()
            ->ownedBy($request->user())
            ->where('account_id', $account->getKey())
            ->latest()
            ->limit(10)
            ->get();

        $exports->each(fn (AccountStatementExport $export) => $this->markExpired($export));

        return view('accounts.statement', [
            'account' => $account,
            'accounts' => $accounts,
            'filters' => $filters,
            'rows' => $this->statements->paginate($account, $filters),
            'exports' => $exports,
            'resourceColumns' => MemberAccountStatementService::resourceColumns(),
            'typeOptions' => MemberAccountStatementService::typeOptions(),
            'statusOptions' => MemberAccountStatementService::statusOptions(),
        ]);
    }

    public function print(AccountStatementRequest $request): View|RedirectResponse
    {
        $accounts = $this->ownedAccounts($request->user());

        if ($accounts->isEmpty()) {
            return redirect()->route('accounts.create');
        }

        $account = $this->selectedAccount($accounts, $request->validated('account_id'));
        $filters = $this->statements->normalizeFilters($request->validated());
        $limit = max(1, (int) config('finance.account_statements.print_row_limit', 2000));
        $rows = $this->statements->collect($account, $filters, $limit + 1);
        $truncated = $rows->count() > $limit;

        return view('accounts.statement-print', [
            'account' => $account,
            'filters' => $filters,
            'rows' => $rows->take($limit),
            'truncated' => $truncated,
            'resourceColumns' => MemberAccountStatementService::resourceColumns(),
        ]);
    }

    public function store(StoreAccountStatementExportRequest $request): StreamedResponse|RedirectResponse
    {
        $account = $this->ownedAccount($request->user(), $request->integer('account_id'));
        $filters = $this->statements->normalizeFilters($request->validated());
        $rowCount = $this->statements->count($account, $filters);
        $syncLimit = max(0, (int) config('finance.account_statements.sync_row_limit', 1000));

        if ($rowCount <= $syncLimit) {
            $this->auditLogger->record(
                category: 'finance',
                action: 'account_statement_downloaded',
                subject: $account,
                context: [
                    'account_id' => $account->getKey(),
                    'filters' => $filters,
                    'row_count' => $rowCount,
                    'delivery' => 'synchronous',
                ],
                message: 'Member downloaded an account statement.',
            );

            return response()->streamDownload(function () use ($account, $filters): void {
                $handle = fopen('php://output', 'wb');

                if ($handle === false) {
                    return;
                }

                try {
                    $this->statements->writeCsv($handle, $account, $filters);
                } finally {
                    fclose($handle);
                }
            }, $this->filename($account, $filters), [
                'Cache-Control' => 'private, no-store, max-age=0',
                'Content-Type' => 'text/csv; charset=UTF-8',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }

        [$export, $created] = $this->findOrCreateExport($request->user(), $account, $filters);

        if ($created) {
            GenerateAccountStatementExport::dispatch($export->getKey());

            $this->auditLogger->record(
                category: 'finance',
                action: 'account_statement_export_requested',
                subject: $export,
                context: [
                    'account_id' => $account->getKey(),
                    'filters' => $filters,
                    'row_count' => $rowCount,
                ],
                message: 'Member requested a queued account statement export.',
            );
        }

        return redirect()
            ->route('accounts.statements.exports.show', $export)
            ->with(
                'alert-message',
                $created
                    ? 'Your export is being prepared. This page shows its current status.'
                    : 'An identical export is already being prepared.'
            )
            ->with('alert-type', 'info');
    }

    public function show(Request $request, AccountStatementExport $statementExport): View
    {
        $export = $this->ownedExport($request->user(), $statementExport);
        $this->markExpired($export);

        return view('accounts.statement-export', [
            'export' => $export,
            'account' => $export->account,
        ]);
    }

    public function download(Request $request, AccountStatementExport $statementExport): StreamedResponse
    {
        $export = $this->ownedExport($request->user(), $statementExport);
        $this->markExpired($export);

        if ($export->hasExpired()) {
            abort(410, 'This export has expired. Create a new statement export.');
        }

        if (! $export->isAvailable()) {
            abort(409, 'This export is not ready to download.');
        }

        if (! Storage::disk('local')->exists((string) $export->path)) {
            $export->forceFill([
                'status' => AccountStatementExport::STATUS_FAILED,
                'path' => null,
                'failure_message' => 'The export file is no longer available. Create a new export.',
                'failed_at' => now(),
            ])->save();

            abort(410, 'This export file is no longer available. Create a new statement export.');
        }

        $this->auditLogger->record(
            category: 'finance',
            action: 'account_statement_export_downloaded',
            subject: $export,
            context: [
                'account_id' => $export->account_id,
                'row_count' => $export->row_count,
            ],
            message: 'Member downloaded a queued account statement export.',
        );

        return Storage::disk('local')->download(
            (string) $export->path,
            $this->filename($export->account, $export->filters),
            [
                'Cache-Control' => 'private, no-store, max-age=0',
                'Content-Type' => 'text/csv; charset=UTF-8',
                'X-Content-Type-Options' => 'nosniff',
            ]
        );
    }

    /**
     * @return Collection<int, Account>
     */
    private function ownedAccounts(User $user): Collection
    {
        return Account::query()
            ->where('nation_id', (int) $user->nation_id)
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  Collection<int, Account>  $accounts
     */
    private function selectedAccount(Collection $accounts, mixed $accountId): Account
    {
        if ($accountId === null) {
            return $accounts->firstOrFail();
        }

        return $accounts->firstWhere('id', (int) $accountId) ?? abort(404);
    }

    private function ownedAccount(User $user, int $accountId): Account
    {
        return Account::query()
            ->where('nation_id', (int) $user->nation_id)
            ->findOrFail($accountId);
    }

    private function ownedExport(User $user, AccountStatementExport $export): AccountStatementExport
    {
        if ((int) $export->user_id !== (int) $user->getKey()) {
            abort(404);
        }

        $accountOwned = Account::query()
            ->withTrashed()
            ->whereKey($export->account_id)
            ->where('nation_id', (int) $user->nation_id)
            ->exists();

        if (! $accountOwned) {
            abort(404);
        }

        return $export->loadMissing('account');
    }

    /**
     * @param  array{from: string, to: string, type: ?string, status: ?string}  $filters
     * @return array{0: AccountStatementExport, 1: bool}
     */
    private function findOrCreateExport(User $user, Account $account, array $filters): array
    {
        $fingerprint = $this->statements->fingerprint($filters);

        try {
            return DB::transaction(function () use ($user, $account, $filters, $fingerprint): array {
                $existing = AccountStatementExport::query()
                    ->ownedBy($user)
                    ->where('account_id', $account->getKey())
                    ->where('request_fingerprint', $fingerprint)
                    ->where('active_key', AccountStatementExport::ACTIVE_KEY_VALUE)
                    ->first();

                if ($existing !== null) {
                    return [$existing, false];
                }

                return [AccountStatementExport::query()->create([
                    'user_id' => $user->getKey(),
                    'account_id' => $account->getKey(),
                    'status' => AccountStatementExport::STATUS_PENDING,
                    'request_fingerprint' => $fingerprint,
                    'filters' => $filters,
                ]), true];
            });
        } catch (UniqueConstraintViolationException) {
            $existing = AccountStatementExport::query()
                ->ownedBy($user)
                ->where('account_id', $account->getKey())
                ->where('request_fingerprint', $fingerprint)
                ->where('active_key', AccountStatementExport::ACTIVE_KEY_VALUE)
                ->firstOrFail();

            return [$existing, false];
        }
    }

    private function markExpired(AccountStatementExport $export): void
    {
        if ($export->status === AccountStatementExport::STATUS_COMPLETED && $export->hasExpired()) {
            $export->forceFill(['status' => AccountStatementExport::STATUS_EXPIRED])->save();
        }
    }

    /**
     * @param  array{from: string, to: string, type: ?string, status: ?string}  $filters
     */
    private function filename(Account $account, array $filters): string
    {
        return "nexus-account-{$account->getKey()}-{$filters['from']}-to-{$filters['to']}.csv";
    }
}
