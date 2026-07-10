<?php

namespace Tests\Unit\Services;

use App\Services\PWHealthService;
use App\Services\QueryService;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Tests\FeatureTestCase;

class PWHealthServiceTest extends FeatureTestCase
{
    public function test_check_and_cache_returns_true_for_valid_response_shape(): void
    {
        Cache::flush();

        $query = $this->createMock(QueryService::class);
        $query->method('sendQuery')->willReturn((object) [
            'requests' => 1,
            'max_requests' => 60,
            'key' => 'abc',
            'permission_bits' => 123,
        ]);

        $service = new PWHealthService($query);

        $this->assertTrue($service->checkAndCache());
        $this->assertSame(5, $query->maxRetries);
    }

    public function test_check_and_cache_returns_false_when_query_throws(): void
    {
        Cache::flush();

        $query = $this->createMock(QueryService::class);
        $query->method('sendQuery')->willThrowException(new RuntimeException('PW unavailable'));

        $service = new PWHealthService($query);

        $this->assertFalse($service->checkAndCache());
        $this->assertSame(5, $query->maxRetries);
    }

    public function test_health_check_disables_query_retries_during_the_probe(): void
    {
        Cache::flush();

        $query = $this->createMock(QueryService::class);
        $query->expects($this->once())
            ->method('sendQuery')
            ->willReturnCallback(function () use ($query): object {
                $this->assertSame(0, $query->maxRetries);

                return (object) [
                    'requests' => 1,
                    'max_requests' => 60,
                    'key' => 'abc',
                    'permission_bits' => 123,
                ];
            });

        $service = new PWHealthService($query);

        $this->assertTrue($service->checkAndCache());
    }
}
