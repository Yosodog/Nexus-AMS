<?php

namespace App\Nel;

use App\Exceptions\UserErrorException;
use App\Services\PWHelperService;

class NationNelHelper
{
    /**
     * @return array<string, callable>
     */
    public function bindings(): array
    {
        return [
            'nation.has_project' => [$this, 'hasProject'],
        ];
    }

    /**
     * @return array<int, string>
     */
    public function functionNames(): array
    {
        return array_keys($this->bindings());
    }

    /**
     * @throws UserErrorException
     */
    public function hasProject(NelEvaluationContext $context, string $name): bool
    {
        if (! in_array($name, PWHelperService::projects(), true)) {
            throw new UserErrorException('Unknown project "'.$name.'".');
        }

        return in_array(
            $name,
            PWHelperService::getNationProjects($context->variables['nation']['project_bits'] ?? null),
            true,
        );
    }
}
