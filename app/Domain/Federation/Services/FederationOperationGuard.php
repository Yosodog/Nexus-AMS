<?php

namespace App\Domain\Federation\Services;

use App\Models\MilcomObjective;
use App\Models\MilcomOperation;
use Closure;
use Illuminate\Validation\ValidationException;

final class FederationOperationGuard
{
    /** @var array<int, true> */
    private array $retirementOperationIds = [];

    public function assertMutable(MilcomOperation $operation, string $action): void
    {
        if ($operation->federation_action_required
            && ! isset($this->retirementOperationIds[(int) $operation->id])) {
            throw ValidationException::withMessages([
                'operation' => "This imported operation is frozen after a federation {$operation->federation_hold_reason}. "
                    .'Continue independently or retire it before making changes.',
                'federation_action' => $action,
            ]);
        }
    }

    public function assertObjectiveMutable(MilcomObjective $objective, string $action): void
    {
        $objective->loadMissing('operation');
        $this->assertMutable($objective->operation, $action);
    }

    public function isRetirement(MilcomOperation $operation): bool
    {
        return isset($this->retirementOperationIds[(int) $operation->id]);
    }

    public function forRetirement(MilcomOperation $operation, Closure $callback): mixed
    {
        $this->retirementOperationIds[(int) $operation->id] = true;

        try {
            return $callback();
        } finally {
            unset($this->retirementOperationIds[(int) $operation->id]);
        }
    }
}
