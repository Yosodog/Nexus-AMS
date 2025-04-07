<?php

namespace App\Services;

use App\Models\Accounts;
use App\Models\GrantApplications;
use App\Models\Grants;
use App\Models\Nations;
use App\Notifications\GrantNotification;
use App\Services\AccountService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;
use Illuminate\Validation\ValidationException;

class GrantService
{
    /**
     * @param Grants $grant
     * @param Nations $nation
     * @param int $accountId
     * @return GrantApplications
     * @throws ValidationException
     */
    public static function applyToGrant(Grants $grant, Nations $nation, int $accountId): GrantApplications
    {
        self::validateEligibility($grant, $nation);

        return self::createApplication($grant, $nation->id, $accountId);
    }

    /**
     * @param Grants $grant
     * @param Nations $nation
     * @return void
     * @throws ValidationException
     */
    public static function validateEligibility(Grants $grant, Nations $nation): void
    {
        // One-time grants: check if they've already been approved
        if ($grant->is_one_time) {
            $alreadyApproved = GrantApplications::where('nation_id', $nation->id)
                ->where('grant_id', $grant->id)
                ->where('status', 'approved')
                ->exists();

            if ($alreadyApproved) {
                throw ValidationException::withMessages([
                    'You have already received this grant.'
                ]);
            }
        }

        // Check if there's a pending application
        $hasPending = GrantApplications::where('nation_id', $nation->id)
            ->where('grant_id', $grant->id)
            ->where('status', 'pending')
            ->exists();

        if ($hasPending) {
            throw ValidationException::withMessages([
                'You already have a pending application for this grant.'
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

    /**
     * @param Grants $grant
     * @param int $nationId
     * @param int $accountId
     * @return GrantApplications
     */
    public static function createApplication(Grants $grant, int $nationId, int $accountId): GrantApplications
    {
        return GrantApplications::create([
            'grant_id' => $grant->id,
            'nation_id' => $nationId,
            'account_id' => $accountId,
            'status' => 'pending',
        ]);
    }

    /**
     * @param GrantApplications $application
     * @return void
     */
    public static function approveGrant(GrantApplications $application): void
    {
        $grant = $application->grant;
        $account = Accounts::findOrFail($application->account_id);
        $adminId = Auth::id();
        $ipAddress = Request::ip();

        $adjustment = [
            'money' => $grant->money,
            'coal' => $grant->coal,
            'oil' => $grant->oil,
            'uranium' => $grant->uranium,
            'iron' => $grant->iron,
            'bauxite' => $grant->bauxite,
            'lead' => $grant->lead,
            'gasoline' => $grant->gasoline,
            'munitions' => $grant->munitions,
            'steel' => $grant->steel,
            'aluminum' => $grant->aluminum,
            'food' => $grant->food,
            'note' => "Grant '{$grant->name}' approved",
        ];

        AccountService::adjustAccountBalance($account, $adjustment, $adminId, $ipAddress);

        $application->update([
            'status' => 'approved',
            'approved_at' => now(),
            'money' => $grant->money,
            'coal' => $grant->coal,
            'oil' => $grant->oil,
            'uranium' => $grant->uranium,
            'iron' => $grant->iron,
            'bauxite' => $grant->bauxite,
            'lead' => $grant->lead,
            'gasoline' => $grant->gasoline,
            'munitions' => $grant->munitions,
            'steel' => $grant->steel,
            'aluminum' => $grant->aluminum,
            'food' => $grant->food,
        ]);

        $nation = $application->nation;

        $nation->notify(new GrantNotification($application->nation_id, $application->fresh(), 'approved'));
    }

    /**
     * @param GrantApplications $application
     * @return void
     */
    public static function denyGrant(GrantApplications $application): void
    {
        $application->update([
            'status' => 'denied',
            'denied_at' => now(),
        ]);

        $application->nation->notify(new GrantNotification($application->nation_id, $application, 'denied'));
    }
}