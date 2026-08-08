<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exceptions\TenantControlTransportException;
use App\Services\TenantControl\TenantControlResponseGuard;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TenantControlResponseGuardTest extends TestCase
{
    public function test_boundary_sized_response_is_accepted(): void
    {
        $guard = new TenantControlResponseGuard;

        $guard->assertHeaders(new Response(202, [
            'Content-Length' => (string) TenantControlResponseGuard::MAX_BYTES,
        ]));
        $guard->assertProgress(
            TenantControlResponseGuard::MAX_BYTES,
            TenantControlResponseGuard::MAX_BYTES,
        );
        $guard->assertBody(str_repeat('a', TenantControlResponseGuard::MAX_BYTES), 202);

        $this->addToAssertionCount(1);
    }

    #[DataProvider('oversizedResponseProvider')]
    public function test_oversized_or_ambiguous_transfer_metadata_is_rejected(
        callable $assertion,
        ?int $expectedStatus,
    ): void {
        try {
            $assertion(new TenantControlResponseGuard);
            $this->fail('Oversized tenant control response metadata was accepted.');
        } catch (TenantControlTransportException $exception) {
            $this->assertSame('invalid_control_response', $exception->failureCode);
            $this->assertFalse($exception->retryable);
            $this->assertSame($expectedStatus, $exception->responseStatus);
            $this->assertSame('Tenant control request failed.', $exception->getMessage());
        }
    }

    /** @return iterable<string, array{callable(TenantControlResponseGuard): void, ?int}> */
    public static function oversizedResponseProvider(): iterable
    {
        yield 'declared size' => [
            static fn (TenantControlResponseGuard $guard) => $guard->assertHeaders(
                new Response(202, ['Content-Length' => '8193']),
            ),
            202,
        ];
        yield 'ambiguous declared size' => [
            static fn (TenantControlResponseGuard $guard) => $guard->assertHeaders(
                new Response(202, ['Content-Length' => '8192, 8193']),
            ),
            202,
        ];
        yield 'reported total' => [
            static fn (TenantControlResponseGuard $guard) => $guard->assertProgress(8_193, 1),
            null,
        ];
        yield 'downloaded bytes' => [
            static fn (TenantControlResponseGuard $guard) => $guard->assertProgress(0, 8_193),
            null,
        ];
        yield 'buffered fallback' => [
            static fn (TenantControlResponseGuard $guard) => $guard->assertBody(str_repeat('a', 8_193), 202),
            202,
        ];
    }
}
