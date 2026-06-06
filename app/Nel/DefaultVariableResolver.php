<?php

namespace App\Nel;

use App\Nel\Exception\NelUnknownVariableException;

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

            if (is_object($current)) {
                $properties = get_object_vars($current);

                if (array_key_exists($segment, $properties)) {
                    $current = $properties[$segment];

                    continue;
                }
            }

            throw new NelUnknownVariableException('Unknown variable path: '.implode('.', $pathSegments));
        }

        return $current;
    }
}
