<?php

namespace App\Domain\Federation\Exceptions;

use App\Domain\Federation\Enums\FederationErrorCode;
use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\JsonResponse;
use RuntimeException;

class FederationProtocolException extends RuntimeException implements ShouldntReport
{
    public function __construct(
        public readonly FederationErrorCode $errorCode,
        public readonly int $httpStatus = 400,
    ) {
        parent::__construct($errorCode->value);
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => $this->errorCode->value,
                'message' => 'The federation request could not be accepted.',
            ],
        ], $this->httpStatus);
    }

    /** @return array{federation_error_code: string} */
    public function context(): array
    {
        return ['federation_error_code' => $this->errorCode->value];
    }
}
