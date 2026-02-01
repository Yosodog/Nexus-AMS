<?php

namespace App\Services;

use App\Models\Account;
use App\Models\PayrollGrade;
use App\Models\PayrollMember;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

class PayrollService
{
    public function __construct(
        private readonly AllianceMembershipService $membershipService,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * @return array{total:int, paid:int, removed:int, skipped_no_account:int, skipped_disabled:int, skipped_other:int}
     */
    public function runDailyPayroll(Carbon $date): array
    {
        $summary = [
            'total' => 0,
            'paid' => 0,
            'removed' => 0,
            'skipped_no_account' => 0,
            'skipped_disabled' => 0,
            'skipped_other' => 0,
        ];

        $runDate = $date->toDateString();

        PayrollMember::query()
            ->where('is_active', true)
            ->with(['grade', 'nation'])
            ->orderBy('id')
            ->chunkById(500, function ($members) use (&$summary, $runDate): void {
                foreach ($members as $member) {
                    $summary['total']++;

                    $nation = $member->nation;

                    if (! $nation || ! $this->membershipService->contains($nation->alliance_id)) {
                        $this->purgeMember($member);
                        $summary['removed']++;

                        continue;
                    }

                    if (! $member->grade || ! $member->grade->is_enabled) {
                        $summary['skipped_disabled']++;

                        continue;
                    }

                    $dailyAmount = $this->calculateDailyAmount((string) $member->grade->weekly_amount);

                    if (bccomp($dailyAmount, '0', 2) <= 0) {
                        $summary['skipped_other']++;

                        continue;
                    }

                    $account = Account::query()
                        ->where('nation_id', $member->nation_id)
                        ->where('frozen', false)
                        ->orderBy('id')
                        ->first();

                    if (! $account) {
                        $summary['skipped_no_account']++;

                        continue;
                    }

                    try {
                        DB::transaction(function () use ($account, $dailyAmount, $member, $runDate): void {
                            $lockedAccount = Account::query()
                                ->whereKey($account->id)
                                ->lockForUpdate()
                                ->first();

                            if (! $lockedAccount || $lockedAccount->frozen) {
                                throw new \RuntimeException('Account unavailable for payroll payout.');
                            }

                            $lockedAccount->money = bcadd((string) $lockedAccount->money, $dailyAmount, 2);
                            $lockedAccount->save();

                            $transaction = new Transaction;
                            $transaction->from_account_id = null;
                            $transaction->to_account_id = $lockedAccount->id;
                            $transaction->nation_id = $member->nation_id;
                            $transaction->transaction_type = 'payroll';
                            $transaction->money = $dailyAmount;
                            $transaction->note = sprintf('Payroll payout (%s)', $member->grade?->name ?? 'grade');
                            $transaction->is_pending = false;
                            $transaction->requires_admin_approval = false;
                            $transaction->payroll_grade_id = $member->payroll_grade_id;
                            $transaction->payroll_member_id = $member->id;
                            $transaction->payroll_run_date = $runDate;

                            foreach (PWHelperService::resources(false) as $resource) {
                                $transaction->{$resource} = 0;
                            }

                            $transaction->save();
                        });

                        $summary['paid']++;
                    } catch (Throwable $exception) {
                        Log::warning('Payroll payout failed', [
                            'nation_id' => $member->nation_id,
                            'payroll_member_id' => $member->id,
                            'message' => $exception->getMessage(),
                        ]);

                        $summary['skipped_other']++;
                    }
                }
            });

        return $summary;
    }

    public function createGrade(array $payload, ?User $admin): PayrollGrade
    {
        $grade = PayrollGrade::query()->create([
            'name' => $payload['name'],
            'weekly_amount' => $payload['weekly_amount'],
            'is_enabled' => $payload['is_enabled'] ?? true,
            'created_by' => $admin?->id,
        ]);

        $this->auditLogger->recordAfterCommit(
            category: 'payroll',
            action: 'payroll_grade_created',
            outcome: 'success',
            severity: 'warning',
            subject: $grade,
            context: [
                'data' => $grade->only(['name', 'weekly_amount', 'is_enabled', 'created_by']),
            ],
            message: 'Payroll grade created.'
        );

        return $grade;
    }

    public function updateGrade(PayrollGrade $grade, array $payload): PayrollGrade
    {
        $before = $grade->only(['name', 'weekly_amount', 'is_enabled']);

        $grade->fill([
            'name' => $payload['name'],
            'weekly_amount' => $payload['weekly_amount'],
            'is_enabled' => $payload['is_enabled'] ?? false,
        ])->save();

        $after = $grade->fresh()->only(['name', 'weekly_amount', 'is_enabled']);
        $changes = [];

        foreach ($after as $field => $value) {
            if ((string) ($before[$field] ?? null) !== (string) $value) {
                $changes[$field] = [
                    'from' => $before[$field] ?? null,
                    'to' => $value,
                ];
            }
        }

        $this->auditLogger->recordAfterCommit(
            category: 'payroll',
            action: 'payroll_grade_updated',
            outcome: 'success',
            severity: 'warning',
            subject: $grade,
            context: [
                'changes' => $changes,
            ],
            message: 'Payroll grade updated.'
        );

        return $grade;
    }

    public function deleteGrade(PayrollGrade $grade): void
    {
        $snapshot = $grade->only(['name', 'weekly_amount', 'is_enabled', 'created_by']);

        $grade->delete();

        $this->auditLogger->recordAfterCommit(
            category: 'payroll',
            action: 'payroll_grade_deleted',
            outcome: 'success',
            severity: 'warning',
            subject: $grade,
            context: [
                'data' => $snapshot,
            ],
            message: 'Payroll grade deleted.'
        );
    }

    public function addMember(int $nationId, int $gradeId, ?User $admin): PayrollMember
    {
        $member = PayrollMember::query()->updateOrCreate(
            ['nation_id' => $nationId],
            [
                'payroll_grade_id' => $gradeId,
                'is_active' => true,
                'created_by' => $admin?->id,
            ]
        );

        $this->auditLogger->recordAfterCommit(
            category: 'payroll',
            action: 'payroll_member_added',
            outcome: 'success',
            severity: 'warning',
            subject: $member,
            context: [
                'data' => [
                    'nation_id' => $member->nation_id,
                    'payroll_grade_id' => $member->payroll_grade_id,
                    'is_active' => $member->is_active,
                ],
            ],
            message: 'Payroll member added.'
        );

        return $member;
    }

    public function updateMember(PayrollMember $member, int $gradeId, ?bool $isActive = null): PayrollMember
    {
        $before = $member->only(['payroll_grade_id', 'is_active']);

        $member->fill([
            'payroll_grade_id' => $gradeId,
            'is_active' => $isActive ?? $member->is_active,
        ])->save();

        $after = $member->fresh()->only(['payroll_grade_id', 'is_active']);
        $changes = [];

        foreach ($after as $field => $value) {
            if ((string) ($before[$field] ?? null) !== (string) $value) {
                $changes[$field] = [
                    'from' => $before[$field] ?? null,
                    'to' => $value,
                ];
            }
        }

        $this->auditLogger->recordAfterCommit(
            category: 'payroll',
            action: 'payroll_member_updated',
            outcome: 'success',
            severity: 'warning',
            subject: $member,
            context: [
                'changes' => $changes,
            ],
            message: 'Payroll member updated.'
        );

        return $member;
    }

    public function removeMember(PayrollMember $member): void
    {
        $member->fill(['is_active' => false])->save();

        $this->auditLogger->recordAfterCommit(
            category: 'payroll',
            action: 'payroll_member_removed',
            outcome: 'success',
            severity: 'warning',
            subject: $member,
            context: [
                'changes' => [
                    'is_active' => [
                        'from' => true,
                        'to' => false,
                    ],
                ],
            ],
            message: 'Payroll member removed.'
        );
    }

    /**
     * Use two-decimal truncation to match stored money precision.
     */
    public function calculateDailyAmount(string $weeklyAmount): string
    {
        return bcdiv($weeklyAmount, '7', 2);
    }

    private function purgeMember(PayrollMember $member): void
    {
        $member->delete();
    }
}
