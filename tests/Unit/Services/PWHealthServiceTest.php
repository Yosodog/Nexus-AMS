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
    }

    public function test_check_and_cache_returns_false_when_query_throws(): void
    {
        Cache::flush();

        $query = $this->createMock(QueryService::class);
        $query->method('sendQuery')->willThrowException(new RuntimeException('PW unavailable'));

        $service = new PWHealthService($query);

        $this->assertFalse($service->checkAndCache());
    }
}
