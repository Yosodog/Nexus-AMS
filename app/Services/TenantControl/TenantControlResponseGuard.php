<?php

declare(strict_types=1);

namespace App\Services\TenantControl;

use App\Exceptions\TenantControlTransportException;
use Psr\Http\Message\ResponseInterface;

final class TenantControlResponseGuard
{
    public const MAX_BYTES = 8_192;

    public function assertHeaders(ResponseInterface $response): void
    {
        $contentLength = $response->getHeaderLine('Content-Length');

        if ($contentLength !== ''
            && (preg_match('/\A[0-9]{1,10}\z/D', $contentLength) !== 1
                || (int) $contentLength > self::MAX_BYTES)) {
            $this->reject($response->getStatusCode());
        }
    }

    public function assertProgress(int|float $downloadTotal, int|float $downloaded): void
    {
        if ($downloadTotal > self::MAX_BYTES || $downloaded > self::MAX_BYTES) {
            $this->reject();
        }
    }

    public function assertBody(string $body, int $responseStatus): void
    {
        if (strlen($body) > self::MAX_BYTES) {
            $this->reject($responseStatus);
        }
    }

    private function reject(?int $responseStatus = null): never
    {
        throw new TenantControlTransportException(
            failureCode: 'invalid_control_response',
            retryable: false,
            responseStatus: $responseStatus,
        );
    }
}
