<?php

namespace Tests\Feature;

use App\Http\Middleware\DiscordVerifiedMiddleware;
use App\Http\Middleware\EnsureMfaConfigured;
use App\Http\Middleware\EnsureUserIsVerified;
use App\Jobs\RefreshNationBuildRecommendationJob;
use App\Models\Nation;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class BuildRecommendationRegenerationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Cache::forever('alliances:membership:ids', [777]);
        Queue::fake();

        $this->withoutMiddleware([
            EnsureUserIsVerified::class,
            DiscordVerifiedMiddleware::class,
            EnsureMfaConfigured::class,
        ]);
    }

    public function test_regeneration_is_rate_limited_per_user(): void
    {
        $user = $this->createMember();

        $this->actingAs($user)
            ->post(route('audit.recommendation.regenerate'))
            ->assertRedirect(route('audit.index'));

        $this->post(route('audit.recommendation.regenerate'))
            ->assertRedirect(route('audit.index'));

        $this->post(route('audit.recommendation.regenerate'))
            ->assertTooManyRequests();

        Queue::assertPushed(RefreshNationBuildRecommendationJob::class, 1);
    }

    public function test_regeneration_jobs_are_unique_per_nation(): void
    {
        $job = new RefreshNationBuildRecommendationJob(123);

        $this->assertInstanceOf(ShouldBeUnique::class, $job);
        $this->assertSame('nation-build-recommendation:123', $job->uniqueId());
        $this->assertSame(300, $job->uniqueFor);
    }

    private function createMember(): User
    {
        $nation = Nation::factory()->create([
            'alliance_id' => 777,
            'alliance_position' => 'MEMBER',
        ]);

        return User::factory()->verified()->create([
            'nation_id' => $nation->id,
        ]);
    }
}
