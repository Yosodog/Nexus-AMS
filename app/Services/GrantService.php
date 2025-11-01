<?php

namespace App\Services;

use App\Models\Account;
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
        return GrantApplication::create([
            'grant_id' => $grant->id,
            'nation_id' => $nationId,
            'account_id' => $accountId,
            'status' => 'pending',
        ]);
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
    }

    public static function denyGrant(GrantApplication $application): void
    {
        $application->update([
            'status' => 'denied',
            'denied_at' => now(),
        ]);

        $application->nation->notify(new GrantNotification($application->nation_id, $application, 'denied'));
    }
}
