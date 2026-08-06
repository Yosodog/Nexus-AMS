<?php

namespace App\Exceptions;

use App\Enums\CityGrantFailureReason;
use RuntimeException;
use Throwable;

final class CityGrantRequestException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public readonly CityGrantFailureReason $reason,
        public readonly array $context = [],
        ?Throwable $previous = null
    ) {
        parent::__construct($reason->value, previous: $previous);
    }
}
