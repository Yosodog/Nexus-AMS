<?php

namespace App\Nel;

use App\Nel\Exception\NelUnknownVariableException;
use ArrayAccess;
use ReflectionException;
use ReflectionMethod;

final class DefaultVariableResolver implements VariableResolver
{
    /**
     * @param  array<int, string>  $pathSegments
     * @return bool|int|float|string|array<mixed>|object|null
     */
    public function resolve(array $root, array $pathSegments): mixed
    {
        $current = $root;

        foreach ($pathSegments as $segment) {
            if (is_array($current) && array_key_exists($segment, $current)) {
                $current = $current[$segment];

                continue;
            }

            if ($current instanceof ArrayAccess && $current->offsetExists($segment)) {
                $current = $current[$segment];

                continue;
            }

            if (is_object($current)) {
                if (property_exists($current, $segment) || isset($current->{$segment})) {
                    $current = $current->{$segment};

                    continue;
                }

                $getter = 'get'.ucfirst($segment);

                if ($this->canCallWithoutParameters($current, $getter)) {
                    $current = $current->{$getter}();

                    continue;
                }

                if ($this->canCallWithoutParameters($current, $segment)) {
                    $current = $current->{$segment}();

                    continue;
                }
            }

            throw new NelUnknownVariableException('Unknown variable path: '.implode('.', $pathSegments));
        }

        return $current;
    }

    private function canCallWithoutParameters(object $object, string $method): bool
    {
        if (! is_callable([$object, $method])) {
            return false;
        }

        try {
            $reflection = new ReflectionMethod($object, $method);

            return $reflection->getNumberOfRequiredParameters() === 0;
        } catch (ReflectionException) {
            return false;
        }
    }
}
