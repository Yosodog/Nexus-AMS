<?php

namespace App\Services\Admin\MemberTimeline;

use App\DataTransferObjects\Admin\MemberTimelineItem;
use App\Enums\MemberTimelineCategory;
use App\Models\Account;
use App\Models\CityGrantRequest;
use App\Models\GrantApplication;
use App\Models\Loan;
use App\Models\Nation;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class FinanceTimelineSource implements MemberTimelineSource
{
    public function category(): MemberTimelineCategory
    {
        return MemberTimelineCategory::Finance;
    }

    public function visibleTo(User $viewer): bool
    {
        return $viewer->canAny(['view-accounts', 'view-loans', 'view-grants', 'view-city-grants']);
    }

    public function items(Nation $nation, User $viewer, int $recordLimit): Collection
    {
        $items = collect();

        if ($viewer->can('view-accounts')) {
            $items->push(...$this->transactionItems($nation, $recordLimit));
        }

        if ($viewer->can('view-loans')) {
            $items->push(...$this->loanItems($nation, $recordLimit));
        }

        if ($viewer->can('view-grants')) {
            $items->push(...$this->grantItems($nation, $recordLimit));
        }

        if ($viewer->can('view-city-grants')) {
            $items->push(...$this->cityGrantItems($nation, $recordLimit));
        }

        return $items;
    }

    /** @return Collection<int, MemberTimelineItem> */
    private function transactionItems(Nation $nation, int $recordLimit): Collection
    {
        $accountIds = Account::query()
            ->where('nation_id', $nation->id)
            ->orderBy('id')
            ->limit($recordLimit)
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        return Transaction::query()
            ->select([
                'id',
                'nation_id',
                'from_account_id',
                'to_account_id',
                'transaction_type',
                'money',
                'is_pending',
                'requires_admin_approval',
                'approved_at',
                'denied_at',
                'refunded_at',
                'sent_at',
                'created_at',
            ])
            ->where(function (Builder $query) use ($nation, $accountIds): void {
                $query->where('nation_id', $nation->id);

                if ($accountIds !== []) {
                    $query->orWhereIn('from_account_id', $accountIds)
                        ->orWhereIn('to_account_id', $accountIds);
                }
            })
            ->latest('created_at')
            ->limit($recordLimit)
            ->get()
            ->map(function (Transaction $transaction) use ($accountIds): MemberTimelineItem {
                $presentation = $this->transactionPresentation($transaction);
                $accountId = collect([$transaction->to_account_id, $transaction->from_account_id])
                    ->filter(fn (mixed $id): bool => $id !== null && in_array((int) $id, $accountIds, true))
                    ->map(fn (mixed $id): int => (int) $id)
                    ->first();
                $amount = abs((float) $transaction->money);
                $type = ucfirst(str_replace('_', ' ', (string) $transaction->transaction_type));

                return new MemberTimelineItem(
                    sourceKey: "transaction:{$transaction->id}",
                    deduplicationKey: "transaction:{$transaction->id}",
                    category: $this->category(),
                    occurredAt: $this->immutable($presentation['occurred_at']),
                    actorKind: 'system',
                    actorLabel: 'Finance ledger',
                    summary: $amount > 0
                        ? "{$type} transaction for $".number_format($amount, 2).'.'
                        : "{$type} resource transaction recorded.",
                    statusLabel: $presentation['label'],
                    statusIntent: $presentation['intent'],
                    statusIcon: $presentation['icon'],
                    sourceUrl: $accountId !== null
                        ? route('admin.accounts.view', ['accounts' => $accountId])
                        : route('admin.accounts.dashboard'),
                    sourceLabel: "transaction #{$transaction->id}",
                    sourcePriority: 60,
                );
            });
    }

    /** @return array{label: string, intent: string, icon: string, occurred_at: DateTimeInterface} */
    private function transactionPresentation(Transaction $transaction): array
    {
        if ($transaction->refunded_at !== null) {
            return ['label' => 'Refunded', 'intent' => 'warning', 'icon' => 'arrow-path', 'occurred_at' => $transaction->refunded_at];
        }

        if ($transaction->denied_at !== null) {
            return ['label' => 'Denied', 'intent' => 'failure', 'icon' => 'x-circle', 'occurred_at' => $transaction->denied_at];
        }

        if ($transaction->sent_at !== null) {
            return ['label' => 'Completed', 'intent' => 'success', 'icon' => 'check-circle', 'occurred_at' => $transaction->sent_at];
        }

        if ((bool) $transaction->is_pending && (bool) $transaction->requires_admin_approval) {
            return ['label' => 'Awaiting review', 'intent' => 'pending', 'icon' => 'clock', 'occurred_at' => $transaction->approved_at ?? $transaction->created_at];
        }

        if ((bool) $transaction->is_pending) {
            return ['label' => 'Processing', 'intent' => 'active', 'icon' => 'arrow-path', 'occurred_at' => $transaction->created_at];
        }

        return ['label' => 'Recorded', 'intent' => 'success', 'icon' => 'check-circle', 'occurred_at' => $transaction->created_at];
    }

    /** @return Collection<int, MemberTimelineItem> */
    private function loanItems(Nation $nation, int $recordLimit): Collection
    {
        return Loan::query()
            ->select(['id', 'nation_id', 'amount', 'status', 'created_at'])
            ->where('nation_id', $nation->id)
            ->latest('created_at')
            ->limit($recordLimit)
            ->get()
            ->map(function (Loan $loan): MemberTimelineItem {
                $presentation = $this->workflowStatusPresentation((string) $loan->status);

                return new MemberTimelineItem(
                    sourceKey: "loan:{$loan->id}",
                    deduplicationKey: "loan:{$loan->id}",
                    category: $this->category(),
                    occurredAt: $this->immutable($loan->created_at),
                    actorKind: 'system',
                    actorLabel: 'Loan workflow',
                    summary: 'Loan request for $'.number_format((float) $loan->amount, 2).'.',
                    statusLabel: $presentation['label'],
                    statusIntent: $presentation['intent'],
                    statusIcon: $presentation['icon'],
                    sourceUrl: route('admin.loans.view', ['Loan' => $loan->id]),
                    sourceLabel: "loan #{$loan->id}",
                );
            });
    }

    /** @return Collection<int, MemberTimelineItem> */
    private function grantItems(Nation $nation, int $recordLimit): Collection
    {
        return GrantApplication::query()
            ->select([
                'id',
                'grant_id',
                'program_name_snapshot',
                'program_version_snapshot',
                'nation_id',
                'status',
                'reviewed_by_user_id',
                'submitted_at',
                'approved_at',
                'denied_at',
                'decided_at',
                'disbursed_at',
                'created_at',
            ])
            ->with([
                'grant:id,name',
                'reviewer' => fn ($query) => $query->without('roles')->select(['id', 'name']),
            ])
            ->where('nation_id', $nation->id)
            ->latest('created_at')
            ->limit($recordLimit)
            ->get()
            ->flatMap(function (GrantApplication $application) use ($nation): array {
                $program = $application->program_name_snapshot ?? $application->grant?->name ?? 'Standard grant';
                $version = $application->program_version_snapshot !== null
                    ? " v{$application->program_version_snapshot}"
                    : '';
                $url = route('admin.grants').'#grant-application-history-title';
                $items = [new MemberTimelineItem(
                    sourceKey: "grant-application:{$application->id}:submitted",
                    deduplicationKey: "grant-application:{$application->id}:submitted",
                    category: $this->category(),
                    occurredAt: $this->immutable($application->submitted_at ?? $application->created_at),
                    actorKind: 'member',
                    actorLabel: $nation->leader_name,
                    summary: "{$program}{$version} application submitted.",
                    statusLabel: 'Submitted',
                    statusIntent: 'pending',
                    statusIcon: 'clock',
                    sourceUrl: $url,
                    sourceLabel: "grant application #{$application->id}",
                )];
                $decidedAt = $application->decided_at ?? $application->approved_at ?? $application->denied_at;

                if ($decidedAt !== null && $application->status !== 'pending') {
                    $presentation = $this->workflowStatusPresentation((string) $application->status);
                    $items[] = new MemberTimelineItem(
                        sourceKey: "grant-application:{$application->id}:decision",
                        deduplicationKey: "grant-application:{$application->id}:decision",
                        category: $this->category(),
                        occurredAt: $this->immutable($decidedAt),
                        actorKind: 'staff',
                        actorLabel: $application->reviewer?->name ?? 'Grant review team',
                        summary: "{$program}{$version} application {$presentation['label']}.",
                        statusLabel: $presentation['label'],
                        statusIntent: $presentation['intent'],
                        statusIcon: $presentation['icon'],
                        sourceUrl: $url,
                        sourceLabel: "grant application #{$application->id}",
                        sourcePriority: 70,
                    );
                }

                if ($application->disbursed_at !== null) {
                    $items[] = new MemberTimelineItem(
                        sourceKey: "grant-application:{$application->id}:disbursed",
                        deduplicationKey: "grant-application:{$application->id}:disbursed",
                        category: $this->category(),
                        occurredAt: $this->immutable($application->disbursed_at),
                        actorKind: 'system',
                        actorLabel: 'Finance ledger',
                        summary: "{$program}{$version} grant disbursed.",
                        statusLabel: 'Disbursed',
                        statusIntent: 'success',
                        statusIcon: 'check-circle',
                        sourceUrl: $url,
                        sourceLabel: "grant application #{$application->id}",
                        sourcePriority: 80,
                    );
                }

                return $items;
            })
            ->values();
    }

    /** @return Collection<int, MemberTimelineItem> */
    private function cityGrantItems(Nation $nation, int $recordLimit): Collection
    {
        return CityGrantRequest::query()
            ->select(['id', 'nation_id', 'city_number', 'grant_amount', 'status', 'approved_at', 'denied_at', 'created_at'])
            ->where('nation_id', $nation->id)
            ->latest('created_at')
            ->limit($recordLimit)
            ->get()
            ->map(function (CityGrantRequest $request): MemberTimelineItem {
                $presentation = $this->workflowStatusPresentation((string) $request->status);
                $occurredAt = $request->approved_at ?? $request->denied_at ?? $request->created_at;

                return new MemberTimelineItem(
                    sourceKey: "city-grant-request:{$request->id}",
                    deduplicationKey: "city-grant-request:{$request->id}",
                    category: $this->category(),
                    occurredAt: $this->immutable($occurredAt),
                    actorKind: 'system',
                    actorLabel: 'City grant workflow',
                    summary: 'City '.$request->city_number.' grant request for $'.number_format((float) $request->grant_amount, 2).'.',
                    statusLabel: $presentation['label'],
                    statusIntent: $presentation['intent'],
                    statusIcon: $presentation['icon'],
                    sourceUrl: route('admin.grants.city'),
                    sourceLabel: "city grant request #{$request->id}",
                );
            });
    }

    /** @return array{label: string, intent: string, icon: string} */
    private function workflowStatusPresentation(string $status): array
    {
        return match ($status) {
            'approved' => ['label' => 'Approved', 'intent' => 'success', 'icon' => 'check-circle'],
            'paid' => ['label' => 'Paid', 'intent' => 'success', 'icon' => 'check-circle'],
            'denied' => ['label' => 'Denied', 'intent' => 'failure', 'icon' => 'x-circle'],
            'missed' => ['label' => 'Past due', 'intent' => 'warning', 'icon' => 'exclamation-triangle'],
            default => ['label' => 'Pending', 'intent' => 'pending', 'icon' => 'clock'],
        };
    }

    private function immutable(mixed $value): CarbonImmutable
    {
        return $value instanceof DateTimeInterface
            ? CarbonImmutable::instance($value)
            : CarbonImmutable::parse((string) $value);
    }
}
