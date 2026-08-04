<?php

namespace Tests\Unit\Milcom;

use App\Jobs\GenerateMilcomRecommendationsJob;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MilcomQueueConfigurationTest extends TestCase
{
    #[Test]
    public function recommendation_timeout_is_safely_below_queue_retry_windows(): void
    {
        $job = new GenerateMilcomRecommendationsJob(1);

        $this->assertSame(120, $job->timeout);
        $this->assertGreaterThan($job->timeout, config('queue.connections.database.retry_after'));
        $this->assertGreaterThan($job->timeout, config('queue.connections.redis.retry_after'));
        $this->assertGreaterThan($job->timeout, config('queue.connections.beanstalkd.retry_after'));
    }
}
