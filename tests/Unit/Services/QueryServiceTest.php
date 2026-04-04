<?php

namespace Tests\Unit\Services;

use App\Exceptions\PWQueryFailedException;
use App\Services\GraphQLQueryBuilder;
use App\Services\QueryService;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Tests\FeatureTestCase;

class QueryServiceTest extends FeatureTestCase
{
    public function test_send_query_retries_on_server_error_and_eventually_succeeds(): void
    {
        Http::fake([
            '*' => Http::sequence()
                ->push('temporary upstream failure', 503)
                ->push([
                    'data' => [
                        'wars' => [
                            'data' => [
                                ['id' => 123],
                            ],
                            'paginatorInfo' => [
                                'perPage' => 1000,
                                'count' => 1,
                                'lastPage' => 1,
                            ],
                        ],
                    ],
                ], 200),
        ]);

        $service = new class extends QueryService
        {
            public int $initialDelay = 0;

            protected function retryTransientResponse(
                Response $response,
                string $query,
                array $variables,
                int &$retryCount,
                int &$delay,
                array $headers = []
            ): PromiseInterface {
                $delay = 0;

                return parent::retryTransientResponse($response, $query, $variables, $retryCount, $delay, $headers);
            }

            protected function retryRejectedRequest(
                mixed $reason,
                string $query,
                array $variables,
                int &$retryCount,
                int &$delay,
                array $headers = []
            ): PromiseInterface {
                $delay = 0;

                return parent::retryRejectedRequest($reason, $query, $variables, $retryCount, $delay, $headers);
            }
        };

        $builder = (new GraphQLQueryBuilder)
            ->setRootField('wars')
            ->addArgument('first', 1)
            ->addNestedField('data', fn ($query) => $query->addFields(['id']))
            ->withPaginationInfo();

        $response = $service->sendQuery($builder);

        $this->assertSame(123, $response->{0}['id']);
        Http::assertSentCount(2);
    }

    public function test_send_query_throws_after_retry_limit_for_server_errors(): void
    {
        Http::fake([
            '*' => Http::response('', 503),
        ]);

        $service = new class extends QueryService
        {
            public int $initialDelay = 0;

            public int $maxRetries = 2;

            protected function retryTransientResponse(
                Response $response,
                string $query,
                array $variables,
                int &$retryCount,
                int &$delay,
                array $headers = []
            ): PromiseInterface {
                $delay = 0;

                return parent::retryTransientResponse($response, $query, $variables, $retryCount, $delay, $headers);
            }
        };

        $builder = (new GraphQLQueryBuilder)
            ->setRootField('wars')
            ->addArgument('first', 1)
            ->addNestedField('data', fn ($query) => $query->addFields(['id']))
            ->withPaginationInfo();

        $this->expectException(PWQueryFailedException::class);
        $this->expectExceptionMessage('Query failed after retries: status=503');

        try {
            $service->sendQuery($builder);
        } finally {
            Http::assertSentCount(3);
        }
    }
}
