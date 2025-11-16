<?php

namespace App\Services;

use App\DataTransferObjects\AllianceFinanceData;
use App\Events\AllianceExpenseOccurred;
use App\Models\Account;
use App\Models\AllianceFinanceEntry;
use App\Models\GrantApplication;
use App\Models\Grants;
use App\Models\Nation;
use App\Notifications\GrantNotification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;
use Illuminate\Validation\ValidationException;

class GrantService
{
    /**
     * @throws ValidationException
     */
    public static function applyToGrant(Grants $grant, Nation $nation, int $accountId): GrantApplication
    {
        self::validateEligibility($grant, $nation);

        return self::createApplication($grant, $nation->id, $accountId);
    }

    /**
     * @throws ValidationException
     */
    public static function validateEligibility(Grants $grant, Nation $nation): void
    {
        // One-time grants: check if they've already been approved
        if ($grant->is_one_time) {
            $alreadyApproved = GrantApplication::where('nation_id', $nation->id)
                ->where('grant_id', $grant->id)
                ->where('status', 'approved')
                ->exists();

            if ($alreadyApproved) {
                throw ValidationException::withMessages([
                    'You have already received this grant.',
                ]);
            }
        }

        // Check if there's a pending application
        $hasPending = GrantApplication::where('nation_id', $nation->id)
            ->where('grant_id', $grant->id)
            ->where('status', 'pending')
            ->exists();

        if ($hasPending) {
            throw ValidationException::withMessages([
                'You already have a pending application for this grant.',
            ]);
        }

        // Run alliance + custom checks
        $validator = new NationEligibilityValidator($nation);
        $validator->validateAllianceMembership();

        $requirements = $grant->validation_rules ?? [];

        // Placeholder for future validation
        // $validator->validateGovernmentType($requirements["government_type"] ?? null);
        // $validator->validateColor($requirements["allowed_colors"] ?? []);
        // $validator->validateRequiredProjects($requirements["required_projects"] ?? []);
        // $validator->validateInfrastructure($requirements["infra_per_city"] ?? 0);
    }

    public static function createApplication(Grants $grant, int $nationId, int $accountId): GrantApplication
    {
        $application = GrantApplication::create([
            'grant_id' => $grant->id,
            'nation_id' => $nationId,
            'account_id' => $accountId,
            'status' => 'pending',
        ]);

        app(PendingRequestsService::class)->flushCache();

        return $application;
    }

    public static function approveGrant(GrantApplication $application): void
    {
        $grant = $application->grant;
        $account = Account::findOrFail($application->account_id);
        $adminId = Auth::id();
        $ipAddress = Request::ip();

        $resources = PWHelperService::resources();
        $adjustment = array_combine($resources, array_map(fn ($r) => $grant->$r, $resources));
        $adjustment['note'] = "Grant '{$grant->name}' approved";

        AccountService::adjustAccountBalance($account, $adjustment, $adminId, $ipAddress);

        $application->update(
            array_merge(
                ['status' => 'approved', 'approved_at' => now()],
                array_combine($resources, array_map(fn ($r) => $grant->$r, $resources))
            )
        );

        $nation = $application->nation;

        $nation->notify(new GrantNotification($application->nation_id, $application->fresh(), 'approved'));

        self::dispatchGrantExpenseEvent($application->fresh(), $grant, $account);

        app(PendingRequestsService::class)->flushCache();
    }

    public static function denyGrant(GrantApplication $application): void
    {
        $application->update([
            'status' => 'denied',
            'denied_at' => now(),
        ]);

        $application->nation->notify(new GrantNotification($application->nation_id, $application, 'denied'));

        app(PendingRequestsService::class)->flushCache();
    }

    /**
     * Count grant applications still pending approval.
     */
    public static function countPending(): int
    {
        return GrantApplication::where('status', 'pending')->count();
    }

    private static function dispatchGrantExpenseEvent(
        GrantApplication $application,
        Grants $grant,
        Account $account
    ): void {
        $financeData = new AllianceFinanceData(
            direction: AllianceFinanceEntry::DIRECTION_EXPENSE,
            category: 'grant',
            description: "Grant '{$grant->name}' approved for Nation #{$application->nation_id}",
            date: now(),
            nationId: $application->nation_id,
            accountId: $account->id,
            source: $application,
            money: (float) ($grant->money ?? 0.0),
            coal: (float) ($grant->coal ?? 0.0),
            oil: (float) ($grant->oil ?? 0.0),
            uranium: (float) ($grant->uranium ?? 0.0),
            iron: (float) ($grant->iron ?? 0.0),
            bauxite: (float) ($grant->bauxite ?? 0.0),
            lead: (float) ($grant->lead ?? 0.0),
            gasoline: (float) ($grant->gasoline ?? 0.0),
            munitions: (float) ($grant->munitions ?? 0.0),
            steel: (float) ($grant->steel ?? 0.0),
            aluminum: (float) ($grant->aluminum ?? 0.0),
            food: (float) ($grant->food ?? 0.0),
            meta: [
                'grant_id' => $grant->id,
                'application_id' => $application->id,
            ]
        );

        event(new AllianceExpenseOccurred($financeData->toArray()));
    }
}
