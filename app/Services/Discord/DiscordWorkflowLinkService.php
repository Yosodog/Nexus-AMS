<?php

namespace App\Services\Discord;

use App\Models\Application;
use App\Models\GrantApplication;
use App\Models\Grants;
use App\Models\Loan;
use Illuminate\Database\Eloquent\Model;

class DiscordWorkflowLinkService
{
    public function availableGrant(Grants $grant): string
    {
        return route('grants.show_grants', ['grant' => $grant->slug], absolute: false);
    }

    public function member(string $type, ?Model $record = null): string
    {
        $recordId = $this->recordId($record);

        return match ($type) {
            'grant' => $this->memberGrant($record),
            'city_grant' => route('grants.city', array_filter(['request' => $recordId]), absolute: false),
            'loan' => route('loans.index', array_filter(['loan' => $recordId]), absolute: false),
            'war_aid' => route('defense.war-aid', array_filter(['request' => $recordId]), absolute: false),
            'rebuilding' => route('defense.rebuilding', array_filter(['request' => $recordId]), absolute: false),
            'withdrawal' => route('accounts', array_filter(['transaction' => $recordId]), absolute: false),
            'member_transfer' => route('accounts', array_filter(['member_transfer' => $recordId]), absolute: false),
            'application' => route('apply.show', array_filter(['application' => $recordId]), absolute: false),
        };
    }

    public function staff(string $type, ?Model $record = null): string
    {
        $recordId = $this->recordId($record);

        return match ($type) {
            'grant' => route('admin.grants', array_filter(['application' => $recordId]), absolute: false),
            'city_grant' => route('admin.grants.city', array_filter(['request' => $recordId]), absolute: false),
            'loan' => $record instanceof Loan && $recordId !== null
                ? route('admin.loans.view', ['Loan' => $record], absolute: false)
                : route('admin.loans', absolute: false),
            'war_aid' => route('admin.war-aid', array_filter(['request' => $recordId]), absolute: false),
            'rebuilding' => route('admin.rebuilding.index', array_filter(['request' => $recordId]), absolute: false),
            'withdrawal' => route('admin.withdrawals.index', array_filter(['transaction' => $recordId]), absolute: false),
            'member_transfer' => route('admin.accounts.dashboard', array_filter(['member_transfer' => $recordId]), absolute: false),
            'application' => $record instanceof Application && $recordId !== null
                ? route('admin.applications.show', ['application' => $record], absolute: false)
                : route('admin.applications.index', absolute: false),
        };
    }

    private function memberGrant(?Model $record): string
    {
        if (! $record?->exists) {
            return route('user.dashboard', absolute: false);
        }

        $grant = match (true) {
            $record instanceof Grants => $record,
            $record instanceof GrantApplication => $record->grant,
            default => null,
        };

        return $grant instanceof Grants
            ? $this->availableGrant($grant)
            : route('user.dashboard', absolute: false);
    }

    private function recordId(?Model $record): int|string|null
    {
        if (! $record?->exists) {
            return null;
        }

        return $record->getRouteKey();
    }
}
