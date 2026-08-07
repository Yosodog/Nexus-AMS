<?php

namespace App\Policies;

use App\Models\MemberInactivityException;
use App\Models\User;

class MemberInactivityExceptionPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->mayManage($user);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, MemberInactivityException $memberInactivityException): bool
    {
        return $this->mayManage($user);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $this->mayManage($user);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, MemberInactivityException $memberInactivityException): bool
    {
        return $this->mayManage($user);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, MemberInactivityException $memberInactivityException): bool
    {
        return $this->mayManage($user);
    }

    private function mayManage(User $user): bool
    {
        return $user->hasPermission('view-members')
            && $user->hasPermission('manage-member-exceptions');
    }
}
