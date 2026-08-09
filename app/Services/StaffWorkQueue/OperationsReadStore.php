<?php

namespace App\Services\StaffWorkQueue;

use App\Models\User;

interface OperationsReadStore
{
    /** @return array<string, mixed> */
    public function forUser(User $user, bool $forceRefresh = false): array;

    /** @return array<string, mixed>|null */
    public function findForUser(User $user, string $workKey, bool $forceRefresh = false): ?array;
}
