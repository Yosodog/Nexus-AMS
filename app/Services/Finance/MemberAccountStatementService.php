<?php

namespace App\Services\Finance;

use App\Models\Account;
use App\Models\DirectDepositLog;
use App\Models\GrowthCircleDistribution;
use App\Models\ManualTransaction;
use App\Models\MMRAssistantPurchase;
use App\Models\Transaction;
use App\Services\PWHelperService;
use App\Support\CsvExport;
use App\Support\Finance\AccountStatementRow;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;
use stdClass;

class MemberAccountStatementService
{
    private const TRANSACTION_STATUS_SQL = <<<'SQL'
        CASE
            WHEN refunded_at IS NOT NULL THEN 'refunded'
            WHEN denied_at IS NOT NULL THEN 'denied'
            WHEN bank_attempt_status = 'needs_reconciliation' THEN 'needs_reconciliation'
            WHEN bank_attempt_status = 'failed' THEN 'failed'
            WHEN is_pending = 1
                OR bank_attempt_status IN ('preparing', 'sending')
                OR (requires_admin_approval = 1 AND sent_at IS NULL)
                THEN 'pending'
            ELSE 'completed'
        END
        SQL;

    /**
     * @return array<string, string>
     */
    public static function typeOptions(): array
    {
        return [
            'deposit' => 'Deposit',
            'withdrawal' => 'Withdrawal',
            'transfer' => 'Transfer',
            'member_transfer' => 'Member transfer',
            'payroll' => 'Payroll',
            'manual_adjustment' => 'Manual adjustment',
            'direct_deposit' => 'Direct Deposit',
            'mmr_purchase' => 'MMR purchase',
            'growth_circle' => 'Growth Circle distribution',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function statusOptions(): array
    {
        return [
            'completed' => 'Completed',
            'pending' => 'Pending',
            'denied' => 'Denied',
            'refunded' => 'Refunded',
            'failed' => 'Failed',
            'needs_reconciliation' => 'Needs reconciliation',
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function resourceColumns(): array
    {
        return PWHelperService::resources();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{from: string, to: string, type: ?string, status: ?string}
     */
    public function normalizeFilters(array $filters): array
    {
        return [
            'from' => filled($filters['from'] ?? null)
                ? (string) $filters['from']
                : now()->subDays(90)->toDateString(),
            'to' => filled($filters['to'] ?? null)
                ? (string) $filters['to']
                : now()->toDateString(),
            'type' => filled($filters['type'] ?? null) ? (string) $filters['type'] : null,
            'status' => filled($filters['status'] ?? null) ? (string) $filters['status'] : null,
        ];
    }

    /**
     * @param  array{from: string, to: string, type: ?string, status: ?string}  $filters
     * @return LengthAwarePaginator<AccountStatementRow>
     */
    public function paginate(Account $account, array $filters, int $perPage = 50): LengthAwarePaginator
    {
        return $this->query($account, $filters)
            ->paginate($perPage)
            ->withQueryString()
            ->through(fn (stdClass $record): AccountStatementRow => $this->toRow($record));
    }

    /**
     * @param  array{from: string, to: string, type: ?string, status: ?string}  $filters
     * @return Collection<int, AccountStatementRow>
     */
    public function collect(Account $account, array $filters, int $limit): Collection
    {
        return $this->query($account, $filters)
            ->limit($limit)
            ->get()
            ->map(fn (stdClass $record): AccountStatementRow => $this->toRow($record));
    }

    /**
     * @param  array{from: string, to: string, type: ?string, status: ?string}  $filters
     */
    public function count(Account $account, array $filters): int
    {
        return $this->query($account, $filters)->count();
    }

    /**
     * @param  resource  $handle
     * @param  array{from: string, to: string, type: ?string, status: ?string}  $filters
     */
    public function writeCsv($handle, Account $account, array $filters): int
    {
        CsvExport::writeRow($handle, [
            'Occurred at',
            'Account ID',
            'Account name',
            'Transaction type',
            'Status',
            'Direction',
            'Reference ID',
            'Source record',
            'Description',
            ...array_map(ucfirst(...), self::resourceColumns()),
        ]);

        $rowCount = 0;

        foreach ($this->cursor($account, $filters) as $row) {
            CsvExport::writeRow($handle, [
                $row->occurredAt->toIso8601String(),
                $account->getKey(),
                $account->name,
                $row->typeLabel,
                $row->statusLabel,
                ucfirst($row->direction),
                $row->referenceId,
                $row->sourceRecordId,
                $row->description,
                ...array_map(
                    fn (string $resource): float => $row->resources[$resource],
                    self::resourceColumns()
                ),
            ]);

            $rowCount++;
        }

        return $rowCount;
    }

    /**
     * @param  array{from: string, to: string, type: ?string, status: ?string}  $filters
     */
    public function fingerprint(array $filters): string
    {
        return hash('sha256', json_encode($filters, JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array{from: string, to: string, type: ?string, status: ?string}  $filters
     * @return LazyCollection<int, AccountStatementRow>
     */
    private function cursor(Account $account, array $filters): LazyCollection
    {
        return $this->query($account, $filters)
            ->cursor()
            ->map(fn (stdClass $record): AccountStatementRow => $this->toRow($record));
    }

    /**
     * @param  array{from: string, to: string, type: ?string, status: ?string}  $filters
     */
    private function query(Account $account, array $filters): Builder
    {
        $transactionQuery = $this->transactionQuery($account, $filters);
        $transactionQuery->unionAll($this->manualAdjustmentQuery($account, $filters));
        $transactionQuery->unionAll($this->directDepositQuery($account, $filters));
        $transactionQuery->unionAll($this->mmrPurchaseQuery($account, $filters));
        $transactionQuery->unionAll($this->growthCircleQuery($account, $filters));

        return DB::query()
            ->fromSub($transactionQuery, 'account_statement_rows')
            ->orderByDesc('occurred_at')
            ->orderByDesc('source_type')
            ->orderByDesc('source_id');
    }

    /**
     * @param  array{from: string, to: string, type: ?string, status: ?string}  $filters
     */
    private function transactionQuery(Account $account, array $filters): EloquentBuilder
    {
        $query = Transaction::query()
            ->where(function (EloquentBuilder $query) use ($account): void {
                $query->where('to_account_id', $account->getKey())
                    ->orWhere('from_account_id', $account->getKey());
            });

        $this->applyDateRange($query, $filters);

        $transactionTypes = ['deposit', 'withdrawal', 'transfer', 'member_transfer', 'payroll'];
        if ($filters['type'] !== null) {
            in_array($filters['type'], $transactionTypes, true)
                ? $query->where('transaction_type', $filters['type'])
                : $query->whereRaw('0 = 1');
        }

        if ($filters['status'] !== null) {
            $query->whereRaw('('.self::TRANSACTION_STATUS_SQL.') = ?', [$filters['status']]);
        }

        $query->selectRaw("'transaction' as source_type")
            ->addSelect([
                'id as source_id',
                'created_at as occurred_at',
                'transaction_type',
            ])
            ->selectRaw(self::TRANSACTION_STATUS_SQL.' as statement_status')
            ->selectRaw('CASE WHEN to_account_id = ? THEN \'incoming\' ELSE \'outgoing\' END as direction', [
                $account->getKey(),
            ])
            ->addSelect([
                'bank_correlation_id as reference_value',
                'bank_record_id as external_reference_id',
                'note as description',
            ]);

        foreach (self::resourceColumns() as $resource) {
            $query->selectRaw(
                "CASE WHEN to_account_id = ? THEN `{$resource}` ELSE (0 - `{$resource}`) END as `{$resource}`",
                [$account->getKey()]
            );
        }

        return $query;
    }

    /**
     * @param  array{from: string, to: string, type: ?string, status: ?string}  $filters
     */
    private function manualAdjustmentQuery(Account $account, array $filters): EloquentBuilder
    {
        $query = ManualTransaction::query()->where('account_id', $account->getKey());
        $this->applyDateRange($query, $filters);
        $this->excludeUnless($query, $filters, 'manual_adjustment');

        $query->selectRaw("'manual_adjustment' as source_type")
            ->addSelect(['id as source_id', 'created_at as occurred_at'])
            ->selectRaw("'manual_adjustment' as transaction_type")
            ->selectRaw("'completed' as statement_status")
            ->selectRaw("'adjustment' as direction")
            ->addSelect(['correlation_id as reference_value'])
            ->selectRaw('NULL as external_reference_id')
            ->addSelect(['note as description']);

        $this->addResourceColumns($query);

        return $query;
    }

    /**
     * @param  array{from: string, to: string, type: ?string, status: ?string}  $filters
     */
    private function directDepositQuery(Account $account, array $filters): EloquentBuilder
    {
        $query = DirectDepositLog::query()
            ->where('account_id', $account->getKey())
            ->where('nation_id', $account->nation_id);
        $this->applyDateRange($query, $filters);
        $this->excludeUnless($query, $filters, 'direct_deposit');

        $query->selectRaw("'direct_deposit' as source_type")
            ->addSelect(['id as source_id', 'created_at as occurred_at'])
            ->selectRaw("'direct_deposit' as transaction_type")
            ->selectRaw("'completed' as statement_status")
            ->selectRaw("'incoming' as direction")
            ->selectRaw('NULL as reference_value')
            ->addSelect(['bank_record_id as external_reference_id'])
            ->selectRaw('NULL as description');

        $this->addResourceColumns($query);

        return $query;
    }

    /**
     * @param  array{from: string, to: string, type: ?string, status: ?string}  $filters
     */
    private function mmrPurchaseQuery(Account $account, array $filters): EloquentBuilder
    {
        $query = MMRAssistantPurchase::query()->where('account_id', $account->getKey());
        $this->applyDateRange($query, $filters);
        $this->excludeUnless($query, $filters, 'mmr_purchase');

        $query->selectRaw("'mmr_purchase' as source_type")
            ->addSelect(['id as source_id', 'created_at as occurred_at'])
            ->selectRaw("'mmr_purchase' as transaction_type")
            ->selectRaw("'completed' as statement_status")
            ->selectRaw("'adjustment' as direction")
            ->selectRaw('NULL as reference_value')
            ->selectRaw('NULL as external_reference_id')
            ->addSelect(['allocation_mode as description'])
            ->selectRaw('(0 - total_spent) as money');

        foreach (array_diff(self::resourceColumns(), ['money']) as $resource) {
            $query->addSelect($resource);
        }

        return $query;
    }

    /**
     * @param  array{from: string, to: string, type: ?string, status: ?string}  $filters
     */
    private function growthCircleQuery(Account $account, array $filters): EloquentBuilder
    {
        $query = GrowthCircleDistribution::query()
            ->where('account_id', $account->getKey())
            ->where('nation_id', $account->nation_id);
        $this->applyDateRange($query, $filters);
        $this->excludeUnless($query, $filters, 'growth_circle');

        $query->selectRaw("'growth_circle' as source_type")
            ->addSelect(['id as source_id', 'created_at as occurred_at'])
            ->selectRaw("'growth_circle' as transaction_type")
            ->selectRaw("'completed' as statement_status")
            ->selectRaw("'incoming' as direction")
            ->selectRaw('NULL as reference_value')
            ->selectRaw('NULL as external_reference_id')
            ->addSelect(['cycle_date as description'])
            ->selectRaw('0 as money');

        foreach (array_diff(self::resourceColumns(), ['money']) as $resource) {
            if (in_array($resource, GrowthCircleDistribution::distributionResourceKeys(), true)) {
                $query->addSelect($resource);
            } else {
                $query->selectRaw("0 as `{$resource}`");
            }
        }

        return $query;
    }

    /**
     * @param  array{from: string, to: string, type: ?string, status: ?string}  $filters
     */
    private function applyDateRange(EloquentBuilder $query, array $filters): void
    {
        $timezone = (string) config('app.timezone', 'UTC');
        $from = CarbonImmutable::parse($filters['from'], $timezone)->startOfDay()->utc();
        $to = CarbonImmutable::parse($filters['to'], $timezone)->endOfDay()->utc();

        $query->whereBetween('created_at', [$from, $to]);
    }

    /**
     * @param  array{from: string, to: string, type: ?string, status: ?string}  $filters
     */
    private function excludeUnless(EloquentBuilder $query, array $filters, string $type): void
    {
        if (($filters['type'] !== null && $filters['type'] !== $type)
            || ($filters['status'] !== null && $filters['status'] !== 'completed')) {
            $query->whereRaw('0 = 1');
        }
    }

    private function addResourceColumns(EloquentBuilder $query): void
    {
        foreach (self::resourceColumns() as $resource) {
            $query->addSelect($resource);
        }
    }

    private function toRow(stdClass $record): AccountStatementRow
    {
        $sourceType = (string) $record->source_type;
        $sourceId = (int) $record->source_id;
        $referenceValue = trim((string) ($record->reference_value ?? ''));
        $externalReferenceId = $record->external_reference_id ?? null;
        $type = (string) $record->transaction_type;
        $status = (string) $record->statement_status;

        $referenceId = match ($sourceType) {
            'transaction' => $referenceValue !== '' ? $referenceValue : "TX-{$sourceId}",
            'manual_adjustment' => $referenceValue !== '' ? $referenceValue : "MAN-{$sourceId}",
            'direct_deposit' => $externalReferenceId !== null
                ? 'BANK-'.(int) $externalReferenceId
                : "DD-{$sourceId}",
            'mmr_purchase' => "MMR-{$sourceId}",
            'growth_circle' => "GC-{$sourceId}",
            default => "RECORD-{$sourceId}",
        };

        $resources = collect(self::resourceColumns())
            ->mapWithKeys(fn (string $resource): array => [
                $resource => (float) ($record->{$resource} ?? 0),
            ])
            ->all();

        return new AccountStatementRow(
            occurredAt: CarbonImmutable::parse((string) $record->occurred_at)->utc(),
            type: $type,
            typeLabel: self::typeOptions()[$type] ?? str($type)->headline()->toString(),
            status: $status,
            statusLabel: self::statusOptions()[$status] ?? str($status)->headline()->toString(),
            direction: (string) $record->direction,
            referenceId: $referenceId,
            sourceRecordId: "{$sourceType}:{$sourceId}",
            description: filled($record->description ?? null) ? (string) $record->description : null,
            resources: $resources,
        );
    }
}
