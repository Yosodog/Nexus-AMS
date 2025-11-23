<?php

namespace App\Nel;

interface VariableResolver
{
    /**
     * @param  array<string, mixed>  $root
     * @param  list<string>  $pathSegments
     */
    public function resolve(array $root, array $pathSegments): mixed;
}
