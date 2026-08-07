<?php

namespace App\Services;

use App\Enums\MemberInactivityAutomation;
use App\Enums\MemberInactivityExceptionCategory;
use App\Models\MemberInactivityException;
use App\Models\Nation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MemberInactivityExceptionService
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(Nation $nation, User $approver, array $data): MemberInactivityException
    {
        [$startsAt, $endsAt] = $this->parseWindow($data);
        $this->assertEndsInFuture($endsAt);

        $exception = DB::transaction(function () use ($nation, $approver, $data, $startsAt, $endsAt): MemberInactivityException {
            Nation::query()->whereKey($nation->getKey())->lockForUpdate()->firstOrFail();
            $this->assertNoOverlap((int) $nation->getKey(), $startsAt, $endsAt);

            $exception = MemberInactivityException::query()->create([
                'nation_id' => $nation->getKey(),
                'category' => MemberInactivityExceptionCategory::from((string) $data['category']),
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'timezone' => (string) $data['timezone'],
                'member_reason' => trim((string) $data['member_reason']),
                'private_notes' => $this->nullableTrimmedString($data['private_notes'] ?? null),
                'affected_automations' => $this->automations($data),
                'approved_by_user_id' => $approver->getKey(),
                'approved_at' => now(),
                'last_reviewed_by_user_id' => $approver->getKey(),
                'last_reviewed_at' => now(),
            ]);

            $this->auditLogger->success(
                category: 'membership',
                action: 'member_inactivity_exception_created',
                subject: $exception,
                context: ['data' => $this->auditData($exception)],
                message: 'Member inactivity exception approved.',
            );

            return $exception;
        });

        return $exception;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(
        Nation $nation,
        MemberInactivityException $exception,
        User $reviewer,
        array $data,
    ): MemberInactivityException {
        [$startsAt, $endsAt] = $this->parseWindow($data);
        $this->assertEndsInFuture($endsAt);
        $now = now();

        $updated = DB::transaction(function () use (
            $nation,
            $exception,
            $reviewer,
            $data,
            $startsAt,
            $endsAt,
            $now,
        ): MemberInactivityException {
            Nation::query()->whereKey($nation->getKey())->lockForUpdate()->firstOrFail();
            $locked = MemberInactivityException::query()
                ->whereKey($exception->getKey())
                ->where('nation_id', $nation->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertEditable($locked, $startsAt, $endsAt, $data, $now);
            $this->assertNoOverlap((int) $nation->getKey(), $startsAt, $endsAt, (int) $locked->getKey());

            $before = $this->auditData($locked);
            $newPrivateNotes = $this->nullableTrimmedString($data['private_notes'] ?? null);
            $privateNotesChanged = $locked->private_notes !== $newPrivateNotes;
            $extended = $endsAt->greaterThan($locked->ends_at);

            $locked->forceFill([
                'category' => MemberInactivityExceptionCategory::from((string) $data['category']),
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'timezone' => (string) $data['timezone'],
                'member_reason' => trim((string) $data['member_reason']),
                'private_notes' => $newPrivateNotes,
                'affected_automations' => $this->automations($data),
                'last_reviewed_by_user_id' => $reviewer->getKey(),
                'last_reviewed_at' => $now,
            ])->save();

            $updated = $locked->fresh();

            $this->auditLogger->success(
                category: 'membership',
                action: 'member_inactivity_exception_updated',
                subject: $updated,
                context: [
                    'changes' => $this->changes($before, $this->auditData($updated)),
                    'data' => [
                        'nation_id' => $updated->nation_id,
                        'extended' => $extended,
                        'private_notes_changed' => $privateNotesChanged,
                    ],
                ],
                message: $extended
                    ? 'Member inactivity exception extended and reviewed.'
                    : 'Member inactivity exception updated and reviewed.',
            );

            return $updated;
        });

        return $updated;
    }

    public function revoke(
        Nation $nation,
        MemberInactivityException $exception,
        User $reviewer,
        string $reason,
    ): MemberInactivityException {
        return DB::transaction(function () use ($nation, $exception, $reviewer, $reason): MemberInactivityException {
            Nation::query()->whereKey($nation->getKey())->lockForUpdate()->firstOrFail();
            $locked = MemberInactivityException::query()
                ->whereKey($exception->getKey())
                ->where('nation_id', $nation->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->revoked_at) {
                return $locked;
            }

            if ($locked->expired_at || $locked->ends_at->lte(now())) {
                throw ValidationException::withMessages([
                    'revocation_reason' => 'Expired exceptions are immutable; create a new exception if more time is required.',
                ]);
            }

            $locked->forceFill([
                'revoked_at' => now(),
                'revoked_by_user_id' => $reviewer->getKey(),
                'revocation_reason' => trim($reason),
                'last_reviewed_at' => now(),
                'last_reviewed_by_user_id' => $reviewer->getKey(),
            ])->save();

            $revoked = $locked->fresh();
            $this->auditLogger->success(
                category: 'membership',
                action: 'member_inactivity_exception_revoked',
                subject: $revoked,
                context: ['data' => [
                    'nation_id' => $revoked->nation_id,
                    'revoked_at' => $revoked->revoked_at?->toIso8601String(),
                ]],
                message: 'Member inactivity exception revoked.',
            );

            return $revoked;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{CarbonImmutable, CarbonImmutable}
     */
    private function parseWindow(array $data): array
    {
        $timezone = (string) $data['timezone'];
        $startsAt = CarbonImmutable::createFromFormat('!Y-m-d\TH:i', (string) $data['starts_at'], $timezone);
        $endsAt = CarbonImmutable::createFromFormat('!Y-m-d\TH:i', (string) $data['ends_at'], $timezone);

        return [$startsAt->utc(), $endsAt->utc()];
    }

    private function assertEndsInFuture(CarbonInterface $endsAt): void
    {
        if ($endsAt->lte(now())) {
            throw ValidationException::withMessages([
                'ends_at' => 'The exception must end in the future.',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function assertEditable(
        MemberInactivityException $exception,
        CarbonInterface $startsAt,
        CarbonInterface $endsAt,
        array $data,
        CarbonInterface $now,
    ): void {
        if ($exception->revoked_at || $exception->expired_at || $exception->ends_at->lte($now)) {
            throw ValidationException::withMessages([
                'ends_at' => 'Expired or revoked exceptions are immutable; create a new exception instead.',
            ]);
        }

        if ($exception->starts_at->lte($now)) {
            if (! $startsAt->equalTo($exception->starts_at)) {
                throw ValidationException::withMessages([
                    'starts_at' => 'An active exception\'s start time cannot be changed.',
                ]);
            }

            if ((string) $data['category'] !== $exception->category->value) {
                throw ValidationException::withMessages([
                    'category' => 'An active exception\'s category cannot be changed.',
                ]);
            }

            if ((string) $data['timezone'] !== $exception->timezone) {
                throw ValidationException::withMessages([
                    'timezone' => 'An active exception\'s source timezone cannot be changed.',
                ]);
            }

            if ($endsAt->lessThan($exception->ends_at)) {
                throw ValidationException::withMessages([
                    'ends_at' => 'An active exception may be extended, but not shortened.',
                ]);
            }
        }
    }

    private function assertNoOverlap(
        int $nationId,
        CarbonInterface $startsAt,
        CarbonInterface $endsAt,
        ?int $exceptId = null,
    ): void {
        $overlapExists = MemberInactivityException::query()
            ->where('nation_id', $nationId)
            ->whereNull('revoked_at')
            ->when($exceptId, fn ($query) => $query->whereKeyNot($exceptId))
            ->where('starts_at', '<', $endsAt)
            ->where('ends_at', '>', $startsAt)
            ->exists();

        if ($overlapExists) {
            throw ValidationException::withMessages([
                'starts_at' => 'This time window overlaps another exception for the member.',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<MemberInactivityAutomation>
     */
    private function automations(array $data): array
    {
        return collect($data['affected_automations'] ?? [])
            ->map(fn (string $value): MemberInactivityAutomation => MemberInactivityAutomation::from($value))
            ->values()
            ->all();
    }

    private function nullableTrimmedString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @return array<string, mixed>
     */
    private function auditData(MemberInactivityException $exception): array
    {
        return [
            'nation_id' => $exception->nation_id,
            'category' => $exception->category->value,
            'starts_at' => $exception->starts_at->toIso8601String(),
            'ends_at' => $exception->ends_at->toIso8601String(),
            'timezone' => $exception->timezone,
            'member_reason' => $exception->member_reason,
            'affected_automations' => $exception->affected_automations->pluck('value')->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return array<string, array{from: mixed, to: mixed}>
     */
    private function changes(array $before, array $after): array
    {
        return collect($after)
            ->filter(fn (mixed $value, string $key): bool => ($before[$key] ?? null) !== $value)
            ->mapWithKeys(fn (mixed $value, string $key): array => [
                $key => ['from' => $before[$key] ?? null, 'to' => $value],
            ])
            ->all();
    }
}
