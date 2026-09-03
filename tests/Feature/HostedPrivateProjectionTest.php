<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\NexusRuntime;
use App\Exceptions\WorldWriteForbidden;
use App\GraphQL\Models\Nation as GraphQLNation;
use App\Models\Nation;
use App\Models\NationMilitary;
use App\Models\NationResources;
use App\Rules\InAllianceAndMember;
use App\Services\AllianceMembershipService;
use App\Services\AuthoritativeNationMembershipService;
use App\Services\Milcom\ReadinessRefreshService;
use App\Services\RuntimeCapabilities;
use App\Services\SubscriptionEventProcessor;
use App\Services\World\NationPrivateProjector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class HostedPrivateProjectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'nexus.runtime' => NexusRuntime::HostedTenant->value,
            'nexus.managed' => true,
        ]);
        $this->app->forgetInstance(RuntimeCapabilities::class);
        $this->app->forgetInstance(NexusRuntime::class);
        Http::preventStrayRequests();
    }

    public function test_private_projection_updates_only_tenant_sidecars(): void
    {
        $nation = Nation::factory()->create([
            'alliance_id' => 777,
            'discord_id' => 'private-discord-canary',
            'tax_id' => 42,
        ]);
        $source = $this->source($nation->id, [
            'money' => 12_345.5,
            'food' => 987.0,
            'soldiers' => 50_000,
            'aircraft' => 250,
        ]);

        $projected = app(NationPrivateProjector::class)->project($source);

        $this->assertTrue($projected?->is($nation));
        $this->assertSame(777, $nation->fresh()->alliance_id);
        $this->assertSame('private-discord-canary', $nation->fresh()->discord_id);
        $this->assertSame(42, $nation->fresh()->tax_id);
        $this->assertSame(12_345.5, (float) NationResources::query()->where('nation_id', $nation->id)->value('money'));
        $this->assertSame(987.0, (float) NationResources::query()->where('nation_id', $nation->id)->value('food'));
        $this->assertSame(50_000, (int) NationMilitary::query()->where('nation_id', $nation->id)->value('soldiers'));
        $this->assertSame(250, (int) NationMilitary::query()->where('nation_id', $nation->id)->value('aircraft'));
    }

    public function test_hosted_readiness_refresh_projects_private_snapshots_without_writing_public_state(): void
    {
        config([
            'services.pw.endpoint' => 'https://pw.test/graphql',
            'services.pw.api_key' => 'testing-key',
        ]);
        $nation = Nation::factory()->create([
            'alliance_id' => 777,
            'alliance_position' => 'MEMBER',
        ]);
        Http::fake([
            'https://pw.test/graphql*' => Http::response([
                'data' => [
                    'nations' => [
                        'data' => [[
                            'id' => $nation->id,
                            'alliance_id' => 888,
                            'alliance_position' => 'MEMBER',
                            'money' => 500.0,
                            'soldiers' => 10_000,
                        ]],
                    ],
                ],
            ]),
        ]);

        $result = app(ReadinessRefreshService::class)->refresh([$nation->id]);

        $this->assertSame([$nation->id], $result->refreshedFrom([$nation->id]));
        $this->assertSame(777, $nation->fresh()->alliance_id);
        $this->assertSame(500.0, (float) NationResources::query()->where('nation_id', $nation->id)->value('money'));
        $this->assertSame(10_000, (int) NationMilitary::query()->where('nation_id', $nation->id)->value('soldiers'));
    }

    public function test_hosted_authority_validation_uses_live_data_without_mutating_the_world_row(): void
    {
        $nation = Nation::factory()->create([
            'alliance_id' => 777,
            'alliance_position' => 'MEMBER',
        ]);
        $membership = $this->createMock(AllianceMembershipService::class);
        $membership->expects($this->once())->method('contains')->with(888)->willReturn(true);
        $liveNation = $this->source($nation->id, [
            'alliance_id' => 888,
            'alliance_position' => 'MEMBER',
        ]);
        $service = new class($membership, $liveNation) extends AuthoritativeNationMembershipService
        {
            public function __construct(
                AllianceMembershipService $membership,
                private readonly GraphQLNation $liveNation,
            ) {
                parent::__construct($membership);
            }

            protected function fetchNation(int $nationId): GraphQLNation
            {
                return $this->liveNation;
            }
        };

        $service->validate($nation->id);

        $this->assertSame(777, $nation->fresh()->alliance_id);
        $this->assertSame('MEMBER', $nation->fresh()->alliance_position);
    }

    public function test_hosted_membership_rule_uses_live_data_without_mutating_the_world_row(): void
    {
        $nation = Nation::factory()->create([
            'alliance_id' => 777,
            'alliance_position' => 'MEMBER',
        ]);
        $membership = $this->createMock(AllianceMembershipService::class);
        $membership->expects($this->once())->method('contains')->with(888)->willReturn(true);
        $liveNation = $this->source($nation->id, [
            'alliance_id' => 888,
            'alliance_position' => 'MEMBER',
        ]);
        $rule = new class($membership, $liveNation) extends InAllianceAndMember
        {
            public function __construct(
                AllianceMembershipService $membership,
                private readonly GraphQLNation $liveNation,
            ) {
                parent::__construct($membership);
            }

            protected function fetchLiveNation(int $nationId): GraphQLNation
            {
                return $this->liveNation;
            }
        };
        $errors = [];

        $rule->validate('nation_id', $nation->id, function (string $message) use (&$errors): void {
            $errors[] = $message;
        });

        $this->assertSame([], $errors);
        $this->assertSame(777, $nation->fresh()->alliance_id);
    }

    public function test_hosted_runtime_does_not_start_the_public_subscription_consumer(): void
    {
        Queue::fake();

        $this->artisan('subs:consume-stream --once')
            ->expectsOutputToContain('Public subscription events are not enabled for this runtime.')
            ->assertSuccessful();

        Queue::assertNothingPushed();
    }

    public function test_hosted_subscription_processor_rejects_public_deletes_before_mutation(): void
    {
        $processor = app(SubscriptionEventProcessor::class);

        $this->expectException(WorldWriteForbidden::class);

        $processor->process('nation', 'delete', [['id' => 1234]]);
    }

    private function source(int $nationId, array $attributes = []): GraphQLNation
    {
        $source = new GraphQLNation;
        $source->buildWithJSON((object) [
            'id' => $nationId,
            ...$attributes,
        ]);

        return $source;
    }
}
