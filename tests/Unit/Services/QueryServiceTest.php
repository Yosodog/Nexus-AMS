<?php

namespace Tests\Unit\Services;

use App\Exceptions\AmbiguousMutationOutcomeException;
use App\Exceptions\DefiniteMutationFailureException;
use App\Exceptions\PWQueryFailedException;
use App\Services\GraphQLQueryBuilder;
use App\Services\QueryService;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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

    public function test_send_query_does_not_retry_mutations_after_ambiguous_server_error(): void
    {
        Http::fake([
            '*' => Http::sequence()
                ->push('temporary upstream failure', 503)
                ->push([
                    'data' => [
                        'bankWithdraw' => [
                            'id' => 987,
                        ],
                    ],
                ], 200),
        ]);

        $service = new QueryService;
        $builder = (new GraphQLQueryBuilder)
            ->setRootField('bankWithdraw')
            ->setMutation()
            ->addFields(['id']);

        $this->expectException(AmbiguousMutationOutcomeException::class);
        $this->expectExceptionMessage('GraphQL mutation failed with an ambiguous upstream response and was not retried');

        try {
            $service->sendQuery($builder);
        } finally {
            Http::assertSentCount(1);
        }
    }

    public function test_send_query_does_not_retry_mutations_after_rate_limit_response(): void
    {
        Http::fake([
            '*' => Http::sequence()
                ->push('rate limited', 429)
                ->push([
                    'data' => [
                        'bankWithdraw' => [
                            'id' => 987,
                        ],
                    ],
                ], 200),
        ]);

        $service = new QueryService;
        $builder = (new GraphQLQueryBuilder)
            ->setRootField('bankWithdraw')
            ->setMutation()
            ->addFields(['id']);

        $this->expectException(AmbiguousMutationOutcomeException::class);
        $this->expectExceptionMessage('GraphQL mutation failed with an ambiguous upstream response and was not retried');

        try {
            $service->sendQuery($builder);
        } finally {
            Http::assertSentCount(1);
        }
    }

    public function test_send_query_classifies_client_rejection_as_definite_mutation_failure(): void
    {
        Http::fake([
            '*' => Http::response(['errors' => [['message' => 'Invalid receiver']]], 422),
        ]);

        $service = new QueryService;
        $builder = (new GraphQLQueryBuilder)
            ->setRootField('bankWithdraw')
            ->setMutation()
            ->addFields(['id']);

        $this->expectException(DefiniteMutationFailureException::class);
        $this->expectExceptionMessage('Query failed: status=422');

        try {
            $service->sendQuery($builder);
        } finally {
            Http::assertSentCount(1);
        }
    }

    public function test_send_query_classifies_unusable_success_response_as_ambiguous_mutation_outcome(): void
    {
        Http::fake([
            '*' => Http::response(['errors' => [['message' => 'Resolver failed after dispatch']]], 200),
        ]);

        $service = new QueryService;
        $builder = (new GraphQLQueryBuilder)
            ->setRootField('bankWithdraw')
            ->setMutation()
            ->addFields(['id']);

        $this->expectException(AmbiguousMutationOutcomeException::class);
        $this->expectExceptionMessage('side effect may have succeeded');

        $service->sendQuery($builder);
    }

    public function test_error_diagnostics_redact_api_credentials(): void
    {
        $apiKey = 'pw-api-secret-value';
        $mutationKey = 'pw-mutation-secret-value';
        config()->set('services.pw.api_key', $apiKey);
        config()->set('services.pw.mutation_key', $mutationKey);
        Log::spy();
        Http::fake([
            '*' => Http::response(
                ['errors' => [['message' => "Rejected api_key={$apiKey} token={$mutationKey}"]]],
                422,
                ['X-Request-ID' => 'request-'.$apiKey, 'X-Api-Key' => $apiKey],
            ),
        ]);

        $service = new QueryService;
        $builder = (new GraphQLQueryBuilder)
            ->setRootField('bankWithdraw')
            ->setMutation()
            ->addFields(['id']);

        try {
            $service->sendQuery($builder);
            $this->fail('The rejected mutation should throw a definite failure.');
        } catch (DefiniteMutationFailureException $exception) {
            $this->assertStringNotContainsString($apiKey, $exception->getMessage());
            $this->assertStringNotContainsString($mutationKey, $exception->getMessage());
            $this->assertStringContainsString('[redacted]', $exception->getMessage());
        }

        Log::shouldHaveReceived('error')
            ->once()
            ->withArgs(function (string $message, array $context) use ($apiKey, $mutationKey): bool {
                $diagnostic = $message.json_encode($context, JSON_THROW_ON_ERROR);

                return ! str_contains($diagnostic, $apiKey)
                    && ! str_contains($diagnostic, $mutationKey)
                    && str_contains($diagnostic, '[redacted]');
            });
    }
}
