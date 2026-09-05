<?php

namespace Tests\Unit\Services;

use App\Services\AllianceMembershipService;
use App\Services\GraphQLQueryBuilder;
use App\Services\QueryService;
use App\Services\TaxBracketService;
use RuntimeException;
use stdClass;
use Tests\TestCase;

class TaxBracketServiceTest extends TestCase
{
    public function test_assignment_uses_credentials_for_the_target_alliance(): void
    {
        $membership = $this->createMock(AllianceMembershipService::class);
        $membership->expects($this->once())
            ->method('getCredentialsForAlliance')
            ->with(888)
            ->willReturn([
                'api_key' => 'offshore-api-key',
                'mutation_key' => 'offshore-mutation-key',
            ]);
        $membership->method('getPrimaryAllianceId')->willReturn(777);
        $this->app->instance(AllianceMembershipService::class, $membership);

        $client = new class extends QueryService
        {
            public ?GraphQLQueryBuilder $sentBuilder = null;

            public bool $sentWithHeaders = false;

            public function sendQuery(
                GraphQLQueryBuilder $builder,
                array $variables = [],
                ?int $maxConcurrency = null,
                bool $headers = false,
                bool $handlePagination = true,
            ): stdClass {
                $this->sentBuilder = $builder;
                $this->sentWithHeaders = $headers;

                return new stdClass;
            }
        };

        $resolvedParameters = null;
        $this->app->bind(QueryService::class, function ($app, array $parameters) use ($client, &$resolvedParameters): QueryService {
            $resolvedParameters = $parameters;

            return $client;
        });

        $assignment = new TaxBracketService;
        $assignment->id = 456;
        $assignment->target_id = 123;
        $assignment->alliance_id = 888;
        $assignment->sendAssign();

        $this->assertSame([
            'apiKey' => 'offshore-api-key',
            'mutationKey' => 'offshore-mutation-key',
        ], $resolvedParameters);
        $this->assertTrue($client->sentWithHeaders);
        $this->assertStringContainsString('assignTaxBracket', $client->sentBuilder?->build() ?? '');
        $this->assertStringContainsString('id: 456', $client->sentBuilder?->build() ?? '');
        $this->assertStringContainsString('target_id: 123', $client->sentBuilder?->build() ?? '');
    }

    public function test_offshore_assignment_requires_offshore_mutation_credentials(): void
    {
        $membership = $this->createMock(AllianceMembershipService::class);
        $membership->method('getCredentialsForAlliance')->with(888)->willReturn([
            'api_key' => 'offshore-api-key',
            'mutation_key' => null,
        ]);
        $membership->method('getPrimaryAllianceId')->willReturn(777);
        $this->app->instance(AllianceMembershipService::class, $membership);

        $assignment = new TaxBracketService;
        $assignment->id = 456;
        $assignment->target_id = 123;
        $assignment->alliance_id = 888;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Mutation credentials are not configured for offshore alliance 888.');

        $assignment->sendAssign();
    }
}
