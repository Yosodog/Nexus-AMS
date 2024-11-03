<?php

namespace App\Services;

use App\Exceptions\PWRateLimitHitException;
use App\Exceptions\PWQueryFailedException;
use Exception;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\Utils;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\LazyCollection;
use stdClass;

class QueryService
{
    /**
     * @var string
     */
    protected string $apiKey;
    /**
     * @var string
     */
    protected string $endpoint;
    /**
     * @var int
     */
    public int $initialDelay = 5;
    /**
     * @var int
     */
    public int $maxRetries = 5;
    /**
     * @var int
     */
    protected int $maxConcurrency = 5;

    /**
     *
     */
    public function __construct()
    {
        $this->apiKey = $this->getAPIKey();
        $this->endpoint = $this->buildEndpoint();
    }

    /**
     * @return string
     * @throws Exception
     */
    protected function getAPIKey(): string
    {
        $apiKey = env("PW_API_KEY");

        if (is_null($apiKey))
            throw new Exception("Env value PW_API_KEY not set");

        return env("PW_API_KEY");
    }

    /**
     * @return string
     */
    protected function buildEndpoint(): string
    {
        return "https://api.politicsandwar.com/graphql?api_key=".$this->apiKey;
    }

    /**
     * @param GraphQLQueryBuilder $builder
     * @param array $variables
     * @param int|null $maxConcurrency
     * @return stdClass
     * @throws PWQueryFailedException|ConnectionException
     */
    public function sendQuery(GraphQLQueryBuilder $builder, array $variables = [], int $maxConcurrency = null): \stdClass
    {
        $maxConcurrency = $maxConcurrency ?? $this->maxConcurrency;
        $paginationEnabled = $builder->includePagination;
        $page = 1;
        $lastPage = 1;
        $allResults = collect();
        $rootField = $builder->getRootField();

        // Initial request to detect pagination and retrieve data
        $firstResponse = $this->executeInitialRequest($builder, $variables, $rootField);
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

        // Fetch remaining pages if there are multiple pages
        while ($page <= $lastPage) {
            // Create and execute a batch of requests, starting from the current page
            $promises = $this->createBatchRequests($builder, $variables, $page, $maxConcurrency, $lastPage);
            $responses = Utils::settle($promises)->wait();

            // Process responses and increment page for the next batch
            $this->processBatchResponses($responses, $allResults, $lastPage, $rootField);
        }

        return (object) $allResults->toArray();
    }

    /**
     * Sends the first query of a request so that we can check later if there's only one page for this request
     *
     * @param GraphQLQueryBuilder $builder
     * @param array $variables
     * @param string $rootField
     * @return array
     * @throws PWQueryFailedException|ConnectionException
     */
    protected function executeInitialRequest(GraphQLQueryBuilder $builder, array $variables, string $rootField): array
    {
        $query = $builder->build();
        $retryCount = 0;
        $delay = $this->initialDelay;

        $response = $this->sendPageQuery($query, $variables, $retryCount, $delay)->wait();

        if ($response && isset($response['data'][$rootField])) {
            return [
                'data' => $response['data'][$rootField]['data'],
                'paginatorInfo' => $response['data'][$rootField]['paginatorInfo'] ?? null,
            ];
        }

        throw new PWQueryFailedException("Initial query failed: " . json_encode($response));
    }

    /**
     * Sends batch requests when we have multiple pages
     *
     * @param GraphQLQueryBuilder $builder
     * @param array $variables
     * @param int $page
     * @param int $maxConcurrency
     * @param int $lastPage
     * @return array
     * @throws ConnectionException
     */
    protected function createBatchRequests(GraphQLQueryBuilder $builder, array $variables, int &$page, int $maxConcurrency, int $lastPage): array
    {
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
            $promises[] = $this->sendPageQuery($currentPageQuery, $variables, $retryCount, $delay);

            // Increment page after setting it for this request
            $page++;
        }

        return $promises;
    }

    /**
     * Processes the batch requests
     *
     * @param array $responses
     * @param $allResults
     * @param $lastPage
     * @param string $rootField
     * @return void
     * @throws PWQueryFailedException
     */
    protected function processBatchResponses(array $responses, &$allResults, &$lastPage, string $rootField)
    {
        foreach ($responses as $response) {
            if ($response['state'] === 'fulfilled' && isset($response['value'])) {
                $data = $response['value']['data'][$rootField];
                $allResults = $allResults->merge($data['data']);

                if (isset($data['paginatorInfo'])) {
                    $lastPage = $data['paginatorInfo']['lastPage'];
                }
            } elseif ($response['state'] === 'rejected') {
                Log::error("Query failed: {$response['reason']}");
                throw new PWQueryFailedException("Query failed: {$response['reason']}");
            }
        }
    }

    /**
     * Sends the query for a request that is not the first page
     *
     * @param string $query
     * @param array $variables
     * @param int $retryCount
     * @param int $delay
     * @return PromiseInterface
     * @throws ConnectionException
     */
    protected function sendPageQuery(string $query, array $variables, int &$retryCount, int &$delay)
    {
        return Http::async()->post($this->endpoint, [
            'query' => $query,
            'variables' => $variables,
        ])->then(
            function ($response) use (&$retryCount, &$delay, $query, $variables) {
                if ($response->status() === 429) {
                    Log::warning('Rate limit hit, retrying in ' . $delay . ' seconds.');
                    sleep($delay);
                    $retryCount++;
                    $delay *= 2;
                    return $this->sendPageQuery($query, $variables, $retryCount, $delay);
                }

                if ($response->successful()) {
                    $retryCount = 0;
                    $delay = $this->initialDelay;
                    return $response->json();
                }

                if ($response->failed()) {
                    Log::error('Query failed: ' . $response->body());
                    throw new PWQueryFailedException('Query failed: ' . $response->body());
                }
            }
        );
    }
}
