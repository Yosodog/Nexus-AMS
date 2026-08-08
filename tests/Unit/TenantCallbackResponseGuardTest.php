<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exceptions\TenantCallbackTransportException;
use App\Services\TenantCallbacks\TenantCallbackResponseGuard;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TenantCallbackResponseGuardTest extends TestCase
{
    public function test_boundary_sized_response_is_accepted(): void
    {
        $guard = new TenantCallbackResponseGuard;

        $guard->assertHeaders(new Response(202, [
            'Content-Length' => (string) TenantCallbackResponseGuard::MAX_BYTES,
        ]));
        $guard->assertProgress(
            TenantCallbackResponseGuard::MAX_BYTES,
            TenantCallbackResponseGuard::MAX_BYTES,
        );
        $guard->assertBody(str_repeat('a', TenantCallbackResponseGuard::MAX_BYTES), 202);

        $this->addToAssertionCount(1);
    }

    #[DataProvider('oversizedResponseProvider')]
    public function test_oversized_or_ambiguous_transfer_metadata_is_rejected(
        callable $assertion,
        ?int $expectedStatus,
    ): void {
        try {
            $assertion(new TenantCallbackResponseGuard);
            $this->fail('Oversized callback response metadata was accepted.');
        } catch (TenantCallbackTransportException $exception) {
            $this->assertSame('invalid_callback_response', $exception->failureCode);
            $this->assertFalse($exception->retryable);
            $this->assertSame($expectedStatus, $exception->responseStatus);
            $this->assertSame('Tenant callback delivery failed.', $exception->getMessage());
        }
    }

    /** @return iterable<string, array{callable(TenantCallbackResponseGuard): void, ?int}> */
    public static function oversizedResponseProvider(): iterable
    {
        yield 'declared size' => [
            static fn (TenantCallbackResponseGuard $guard) => $guard->assertHeaders(
                new Response(202, ['Content-Length' => '8193']),
            ),
            202,
        ];
        yield 'ambiguous declared size' => [
            static fn (TenantCallbackResponseGuard $guard) => $guard->assertHeaders(
                new Response(202, ['Content-Length' => '8192, 8193']),
            ),
            202,
        ];
        yield 'reported total' => [
            static fn (TenantCallbackResponseGuard $guard) => $guard->assertProgress(8_193, 1),
            null,
        ];
        yield 'downloaded bytes' => [
            static fn (TenantCallbackResponseGuard $guard) => $guard->assertProgress(0, 8_193),
            null,
        ];
        yield 'buffered fallback' => [
            static fn (TenantCallbackResponseGuard $guard) => $guard->assertBody(str_repeat('a', 8_193), 202),
            202,
        ];
    }
}
