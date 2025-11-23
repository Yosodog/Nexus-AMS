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
        $projects = PWHelperService::projects();
        $index = array_search($name, $projects, true);

        if ($index === false) {
            throw new UserErrorException('Unknown project "'.$name.'".');
        }

        $projectBits = $this->normalizeProjectBits($context->variables['nation']['project_bits'] ?? '', count($projects));

        $bitPosition = strlen($projectBits) - 1 - $index;

        if ($bitPosition < 0 || ! isset($projectBits[$bitPosition])) {
            return false;
        }

        return $projectBits[$bitPosition] === '1';
    }

    private function normalizeProjectBits(mixed $raw, int $projectCount): string
    {
        $bits = (string) $raw;

        if (! preg_match('/^[01]+$/', $bits)) {
            $bits = decbin((int) $bits);
        }

        return str_pad($bits, $projectCount, '0', STR_PAD_LEFT);
    }
}
