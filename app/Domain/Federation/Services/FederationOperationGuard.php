<?php

namespace App\Domain\Federation\Services;

use App\Models\MilcomObjective;
use App\Models\MilcomOperation;
use Closure;
use Illuminate\Validation\ValidationException;

final class FederationOperationGuard
{
    public const HELD_ERROR_CODE = 'federation_action_required';

    public const HELD_ERROR_MESSAGE = 'This imported operation is frozen pending federation action.';

    /** @var array<int, true> */
    private array $retirementOperationIds = [];

    public function isHeld(MilcomOperation $operation): bool
    {
        return (bool) $operation->federation_action_required
            && ! $this->isRetirement($operation);
    }

    public function assertMutable(MilcomOperation $operation, string $action): void
    {
        if ($this->isHeld($operation)) {
            throw ValidationException::withMessages([
                'operation' => self::HELD_ERROR_MESSAGE,
                'federation_error' => self::HELD_ERROR_CODE,
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
