<?php

namespace App\Services;

use App\Exceptions\PWQueryFailedException;
use Exception;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\Utils;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use stdClass;

class QueryService
{
    public int $initialDelay = 5;

    public int $maxRetries = 5;

    protected string $apiKey;

    protected string $endpoint;

    protected ?string $mutationKey = null;

    protected int $maxConcurrency = 5;

    public function __construct(?string $apiKey = null, ?string $mutationKey = null)
    {
        $this->apiKey = $apiKey ?? $this->getAPIKey();
        $this->mutationKey = $mutationKey ?? $this->getMutationKey();
        $this->endpoint = $this->buildEndpoint();
    }

    /**
     * @throws Exception
     */
    protected function getAPIKey(): string
    {
        $apiKey = env('PW_API_KEY');

        if (is_null($apiKey)) {
            throw new Exception('Env value PW_API_KEY not set');
        }

        return $apiKey;
    }

    protected function getMutationKey(): ?string
    {
        return env('PW_API_MUTATION_KEY');
    }

    protected function buildEndpoint(): string
    {
        return 'https://api.politicsandwar.com/graphql?api_key='.$this->apiKey;
    }

    /**
     * @throws ConnectionException
     * @throws PWQueryFailedException
     */
    public function sendQuery(
        GraphQLQueryBuilder $builder,
        array $variables = [],
        ?int $maxConcurrency = null,
        bool $headers = false,
        bool $handlePagination = true
    ): stdClass {
        $maxConcurrency = $maxConcurrency ?? $this->maxConcurrency;
        $paginationEnabled = $builder->includePagination;
        $page = 1;
        $lastPage = 1;
        $allResults = collect();
        $rootField = $builder->getRootField();

        if ($headers) {
            $headersArray = $this->getHeaders();
        } else {
            $headersArray = [];
        }

        // Initial request to detect pagination and retrieve data
        $firstResponse = $this->executeInitialRequest(
            $builder,
            $variables,
            $rootField,
            $headersArray
        );
        $paginationEnabled = isset($firstResponse['paginatorInfo']);
        $allResults = $allResults->merge($firstResponse['data']);

        if ($paginationEnabled) {
            $lastPage = $firstResponse['paginatorInfo']['lastPage'];
            if ($lastPage === 1) {
                return (object) $allResults->toArray();
            }
        } else {
            return (object) $allResults->toArray();
        }

        // Fetch remaining pages if there are multiple pages, and if we want to
        while ($page <= $lastPage && $handlePagination == true) {
            // Create and execute a batch of requests, starting from the current page
            echo 'Page: '.$page."\n";
            $promises = $this->createBatchRequests(
                $builder,
                $variables,
                $page,
                $maxConcurrency,
                $lastPage
            );
            $responses = Utils::settle($promises)->wait();

            // Process responses and increment page for the next batch
            $this->processBatchResponses(
                $responses,
                $allResults,
                $lastPage,
                $rootField
            );
        }

        return (object) $allResults->toArray();
    }

    /**
     * Gets headers for mutations that require these.
     */
    protected function getHeaders(): array
    {
        return array_filter([
            'X-Bot-Key' => $this->mutationKey,
            'X-Api-Key' => $this->apiKey,
        ], fn ($value) => ! is_null($value));
    }

    /**
     * Sends the first query of a request so that we can check later if there's
     * only one page for this request
     *
     *
     * @throws ConnectionException
     * @throws PWQueryFailedException
     */
    protected function executeInitialRequest(
        GraphQLQueryBuilder $builder,
        array $variables,
        string $rootField,
        array $headers = []
    ): array {
        $query = $builder->build();
        $retryCount = 0;
        $delay = $this->initialDelay;

        $response = $this->sendPageQuery($query, $variables, $retryCount, $delay, $headers)->wait();

        if ($response && isset($response['data'][$rootField])) {
            if (isset($response['data'][$rootField]['data'])) {
                return [
                    'data' => $response['data'][$rootField]['data'],
                    'paginatorInfo' => $response['data'][$rootField]['paginatorInfo'] ?? null,
                ];
            } else {
                return [
                    'data' => $response['data'][$rootField],
                    'paginatorInfo' => $response['data'][$rootField]['paginatorInfo'] ?? null,
                ];
            }
        }

        throw new PWQueryFailedException('Initial query failed: '.json_encode($response));
    }

    /**
     * Sends the query for a request that is not the first page
     *
     *
     * @throws ConnectionException
     */
    protected function sendPageQuery(
        string $query,
        array $variables,
        int &$retryCount,
        int &$delay,
        array $headers = []
    ): PromiseInterface {
        return Http::async()->withHeaders($headers)->post(
            $this->endpoint,
            ['query' => $query, 'variables' => $variables]
        )->then(
            function ($response) use (&$retryCount, &$delay, $query, $variables, $headers) {
                try {
                    if ($response instanceof Response) {
                        if ($response->status() === 429) {
                            Log::warning('Rate limit hit, retrying in '.$delay.' seconds.');
                            sleep($delay);
                            $retryCount++;
                            $delay *= 2;

                            return $this->sendPageQuery($query, $variables, $retryCount, $delay, $headers);
                        }

                        if ($response->successful()) {
                            $retryCount = 0;
                            $delay = $this->initialDelay;

                            return $response->json();
                        }

                        Log::error('Query failed: '.$response->body());
                        throw new PWQueryFailedException('Query failed: '.$response->body());
                    }
                } catch (ConnectionException $e) {
                    Log::error('Connection exception: '.$e->getMessage());
                    throw new ConnectionException('Failed to connect to the Politics & War API.');
                }
            }
        );
    }

    /**
     * Sends batch requests when we have multiple pages
     *
     *
     * @throws ConnectionException
     */
    protected function createBatchRequests(
        GraphQLQueryBuilder $builder,
        array $variables,
        int &$page,
        int $maxConcurrency,
        int $lastPage
    ): array {
        $promises = [];
        $page++; // Increment page because we can assume we are already on page 2 if we hit this function

        // Loop up to the max concurrency or remaining pages
        for ($i = 0; $i < $maxConcurrency && $page <= $lastPage; $i++) {
            // Clone the builder and add the current page argument
            $pageBuilder = clone $builder;
            $pageBuilder->addArgument('page', $page);

            // Build the query with the updated page argument
            $currentPageQuery = $pageBuilder->build();

            // Prepare the request with retry settings
            $retryCount = 0;
            $delay = $this->initialDelay;
            $promises[] = $this->sendPageQuery(
                $currentPageQuery,
                $variables,
                $retryCount,
                $delay
            );

            // Increment page after setting it for this request
            $page++;
        }

        return $promises;
    }

    /**
     * Processes the batch requests
     *
     *
     * @return void
     *
     * @throws PWQueryFailedException
     */
    protected function processBatchResponses(array $responses, &$allResults, &$lastPage, string $rootField)
    {
        foreach ($responses as $response) {
            if ($response['state'] === 'fulfilled'
                && isset($response['value'])
            ) {
                $data = $response['value']['data'][$rootField];
                $allResults = $allResults->merge($data['data']);

                if (isset($data['paginatorInfo'])) {
                    $lastPage = $data['paginatorInfo']['lastPage'];
                }
            } elseif ($response['state'] === 'rejected') {
                Log::error("Query failed: {$response['reason']}");
                throw new PWQueryFailedException(
                    "Query failed: {$response['reason']}"
                );
            }
        }
    }

    /**
     * @return Collection
     *
     * @throws ConnectionException
     * @throws PWQueryFailedException
     */
    public function getPaginationInfo(GraphQLQueryBuilder $builder, array $variables = [], bool $headers = false)
    {
        if ($headers) {
            $headersArray = $this->getHeaders();
        } else {
            $headersArray = [];
        }

        $rootField = $builder->getRootField();
        $results = collect();

        // Initial request to detect pagination and retrieve data
        $firstResponse = $this->executeInitialRequest(
            $builder,
            $variables,
            $rootField,
            $headersArray
        );

        $results = $results->merge($firstResponse['paginatorInfo']);

        return $results;
    }
}
