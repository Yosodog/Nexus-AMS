<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DataTransferObjects\BootstrapClaims;

interface BootstrapTokenIntrospector
{
    public function introspect(string $tokenHash): BootstrapClaims;
}
