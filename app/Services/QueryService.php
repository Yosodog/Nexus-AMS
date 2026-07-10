<?php

namespace App\Services;

use App\Exceptions\AmbiguousMutationOutcomeException;
use App\Exceptions\DefiniteMutationFailureException;
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
use Throwable;

class QueryService
{
    private const MAX_DIAGNOSTIC_LENGTH = 1000;

    public int $initialDelay = 5;

    public int $maxRetries = 5;

    protected ?string $apiKey = null;

    protected ?string $endpoint = null;

    protected ?string $mutationKey = null;

    protected int $maxConcurrency = 5;

    public function __construct(?string $apiKey = null, ?string $mutationKey = null)
    {
        $this->apiKey = $apiKey;
        $this->mutationKey = $mutationKey;
    }

    /**
     * @throws Exception
     */
    protected function apiKey(): string
    {
        if (! blank($this->apiKey)) {
            return $this->apiKey;
        }

        $key = config('services.pw.api_key');

        if (blank($key)) {
            // Throw only when the service is actually USED to call the API.
            throw new Exception('Env value PW_API_KEY not set');
        }

        return $this->apiKey = $key;
    }

    protected function mutationKey(): ?string
    {
        if ($this->mutationKey !== null) {
            return $this->mutationKey;
        }

        // Use config(), not env()
        return $this->mutationKey = config('services.pw.mutation_key');
    }

    protected function endpoint(): string
    {
        if (! blank($this->endpoint)) {
            return $this->endpoint;
        }

        $base = config('services.pw.endpoint', 'https://api.politicsandwar.com/graphql');

        // only now do we require apiKey()
        $this->endpoint = rtrim($base, '?').'?'.http_build_query([
            'api_key' => $this->apiKey(),
        ]);

        return $this->endpoint;
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
        $allowTransientRetries = ! $builder->isMutation();

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
            $headersArray,
            $allowTransientRetries
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
            $promises = $this->createBatchRequests(
                $builder,
                $variables,
                $page,
                $maxConcurrency,
                $lastPage,
                $allowTransientRetries
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
            'X-Bot-Key' => $this->mutationKey(),
            'X-Api-Key' => $this->apiKey(),
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
        array $headers = [],
        bool $allowTransientRetries = true
    ): array {
        $query = $builder->build();
        $retryCount = 0;
        $delay = $this->initialDelay;

        $response = $this->sendPageQuery($query, $variables, $retryCount, $delay, $headers, $allowTransientRetries)->wait();

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

        $message = 'Initial query failed: '.$this->sanitizeDiagnosticText(json_encode($response));

        if (! $allowTransientRetries) {
            throw new AmbiguousMutationOutcomeException(
                'GraphQL mutation returned an unusable response after the request was sent; the side effect may have succeeded: '.$message
            );
        }

        throw new PWQueryFailedException($message);
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
        array $headers = [],
        bool $allowTransientRetries = true
    ): PromiseInterface {
        try {
            $endpoint = $this->endpoint();
        } catch (Throwable $exception) {
            if (! $allowTransientRetries) {
                throw new DefiniteMutationFailureException(
                    'GraphQL mutation could not be prepared before dispatch.',
                    previous: $exception,
                );
            }

            throw $exception;
        }

        return Http::async()->withHeaders($headers)->post(
            $endpoint,
            ['query' => $query, 'variables' => $variables]
        )->then(
            function ($response) use (&$retryCount, &$delay, $query, $variables, $headers, $allowTransientRetries) {
                try {
                    if ($response instanceof Response) {
                        if ($response->status() === 429) {
                            if (! $allowTransientRetries) {
                                $this->throwAmbiguousMutationResponse($response);
                            }

                            $resetAfter = $this->getRateLimitResetAfter($response) ?? $delay;
                            if ($retryCount >= $this->maxRetries) {
                                Log::error('Rate limit retry limit reached.', [
                                    'retryCount' => $retryCount,
                                    'resetAfter' => $resetAfter,
                                ]);
                                throw new PWQueryFailedException('Rate limit retry limit reached.');
                            }

                            Log::warning('Rate limit hit, retrying in '.$resetAfter.' seconds.');
                            sleep($resetAfter);
                            $retryCount++;
                            $delay *= 2;

                            return $this->sendPageQuery($query, $variables, $retryCount, $delay, $headers, $allowTransientRetries);
                        }

                        $this->sleepForRateLimitRemaining($response);

                        if ($this->shouldRetryResponse($response)) {
                            if (! $allowTransientRetries) {
                                $this->throwAmbiguousMutationResponse($response);
                            }

                            return $this->retryTransientResponse(
                                $response,
                                $query,
                                $variables,
                                $retryCount,
                                $delay,
                                $headers
                            );
                        }

                        if ($response->successful()) {
                            $retryCount = 0;
                            $delay = $this->initialDelay;

                            return $response->json();
                        }

                        $context = [
                            'status' => $response->status(),
                            'reason' => $this->sanitizeDiagnosticText($response->reason()),
                            'request_id' => $this->sanitizeDiagnosticText($response->header('X-Request-ID')),
                            'response_detail' => $this->responseDetail($response),
                        ];

                        Log::error('Query failed.', $context);

                        $message = sprintf(
                            'Query failed: status=%d reason=%s body=%s',
                            $response->status(),
                            $this->sanitizeDiagnosticText($response->reason() ?: 'unknown'),
                            $this->responseDetail($response)
                        );

                        if (! $allowTransientRetries) {
                            throw new DefiniteMutationFailureException($message);
                        }

                        throw new PWQueryFailedException($message);
                    }
                } catch (ConnectionException) {
                    Log::error('Connection exception while calling the Politics & War API.');
                    throw new ConnectionException('Failed to connect to the Politics & War API.');
                }
            },
            function ($reason) use (&$retryCount, &$delay, $query, $variables, $headers, $allowTransientRetries) {
                if (! $allowTransientRetries) {
                    $this->throwAmbiguousMutationRejection($reason);
                }

                return $this->retryRejectedRequest(
                    $reason,
                    $query,
                    $variables,
                    $retryCount,
                    $delay,
                    $headers
                );
            }
        );
    }

    protected function shouldRetryResponse(Response $response): bool
    {
        return $response->serverError() || $response->status() === 408;
    }

    /**
     * @throws AmbiguousMutationOutcomeException
     */
    protected function throwAmbiguousMutationResponse(Response $response): never
    {
        $message = sprintf(
            'GraphQL mutation failed with an ambiguous upstream response and was not retried: status=%d reason=%s body=%s',
            $response->status(),
            $this->sanitizeDiagnosticText($response->reason() ?: 'unknown'),
            $this->responseDetail($response)
        );

        Log::error('GraphQL mutation returned an ambiguous upstream response.', [
            'status' => $response->status(),
            'reason' => $this->sanitizeDiagnosticText($response->reason()),
            'request_id' => $this->sanitizeDiagnosticText($response->header('X-Request-ID')),
            'response_detail' => $this->responseDetail($response),
        ]);

        throw new AmbiguousMutationOutcomeException($message);
    }

    /**
     * @throws AmbiguousMutationOutcomeException
     */
    protected function throwAmbiguousMutationRejection(mixed $reason): never
    {
        $message = $this->rejectionDetail($reason);

        Log::error('GraphQL mutation request was rejected and was not retried.', [
            'reason' => $message,
        ]);

        throw new AmbiguousMutationOutcomeException(
            "GraphQL mutation request failed without retry because the side effect may have succeeded: {$message}"
        );
    }

    protected function retryTransientResponse(
        Response $response,
        string $query,
        array $variables,
        int &$retryCount,
        int &$delay,
        array $headers = []
    ): PromiseInterface {
        if ($retryCount >= $this->maxRetries) {
            $message = sprintf(
                'Query failed after retries: status=%d reason=%s body=%s',
                $response->status(),
                $this->sanitizeDiagnosticText($response->reason() ?: 'unknown'),
                $this->responseDetail($response)
            );

            Log::error('Query failed after retry limit.', [
                'retryCount' => $retryCount,
                'status' => $response->status(),
                'reason' => $this->sanitizeDiagnosticText($response->reason()),
                'request_id' => $this->sanitizeDiagnosticText($response->header('X-Request-ID')),
                'response_detail' => $this->responseDetail($response),
            ]);

            throw new PWQueryFailedException($message);
        }

        $waitSeconds = max(1, $delay);

        Log::warning('Transient query failure, retrying request.', [
            'status' => $response->status(),
            'reason' => $this->sanitizeDiagnosticText($response->reason()),
            'retryCount' => $retryCount,
            'nextDelaySeconds' => $waitSeconds,
            'request_id' => $this->sanitizeDiagnosticText($response->header('X-Request-ID')),
            'response_detail' => $this->responseDetail($response),
        ]);

        sleep($waitSeconds);
        $retryCount++;
        $delay *= 2;

        return $this->sendPageQuery($query, $variables, $retryCount, $delay, $headers);
    }

    protected function retryRejectedRequest(
        mixed $reason,
        string $query,
        array $variables,
        int &$retryCount,
        int &$delay,
        array $headers = []
    ): PromiseInterface {
        $message = $this->rejectionDetail($reason);

        if ($retryCount >= $this->maxRetries) {
            Log::error('Query request rejected after retries.', [
                'retryCount' => $retryCount,
                'reason' => $message,
            ]);

            throw new ConnectionException("Failed to connect to the Politics & War API after retries: {$message}");
        }

        $waitSeconds = max(1, $delay);

        Log::warning('Query request rejected, retrying.', [
            'retryCount' => $retryCount,
            'nextDelaySeconds' => $waitSeconds,
            'reason' => $message,
        ]);

        sleep($waitSeconds);
        $retryCount++;
        $delay *= 2;

        return $this->sendPageQuery($query, $variables, $retryCount, $delay, $headers);
    }

    protected function sleepForRateLimitRemaining(Response $response): void
    {
        $remaining = $response->header('X-RateLimit-Remaining');
        if (! is_numeric($remaining) || (int) $remaining !== 0) {
            return;
        }

        $resetAfter = $this->getRateLimitResetAfter($response);
        if (is_null($resetAfter) || $resetAfter <= 0) {
            return;
        }

        Log::warning('Rate limit exhausted, pausing for '.$resetAfter.' seconds.');
        sleep($resetAfter);
    }

    protected function getRateLimitResetAfter(Response $response): ?int
    {
        $resetAfter = $response->header('X-RateLimit-Reset-After');
        if (is_numeric($resetAfter)) {
            return max(0, (int) ceil((float) $resetAfter));
        }

        $resetAt = $response->header('X-RateLimit-Reset');
        if (is_numeric($resetAt)) {
            $seconds = (int) $resetAt - now()->timestamp;
            if ($seconds > 0) {
                return $seconds;
            }
        }

        return null;
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
        int $lastPage,
        bool $allowTransientRetries = true
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
                $delay,
                allowTransientRetries: $allowTransientRetries
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
                $reason = $this->rejectionDetail($response['reason']);
                Log::error('Batch query failed.', ['reason' => $reason]);
                throw new PWQueryFailedException(
                    "Query failed: {$reason}"
                );
            }
        }
    }

    /**
     * @return Collection<int|string, mixed>
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
            $headersArray,
            ! $builder->isMutation()
        );

        $results = $results->merge($firstResponse['paginatorInfo']);

        return $results;
    }

    protected function responseDetail(Response $response): string
    {
        $errors = data_get($response->json(), 'errors', []);
        $messages = collect(is_array($errors) ? $errors : [])
            ->pluck('message')
            ->filter(fn (mixed $message): bool => is_string($message) && $message !== '')
            ->take(3)
            ->implode('; ');

        return $messages === ''
            ? '[response body omitted]'
            : $this->sanitizeDiagnosticText($messages);
    }

    protected function rejectionDetail(mixed $reason): string
    {
        $message = $reason instanceof Throwable ? $reason->getMessage() : (string) $reason;

        return $this->sanitizeDiagnosticText($message);
    }

    protected function sanitizeDiagnosticText(string|false|null $value): string
    {
        $text = (string) $value;
        $secrets = array_filter([
            $this->apiKey,
            $this->mutationKey,
            config('services.pw.api_key'),
            config('services.pw.mutation_key'),
        ], fn (mixed $secret): bool => is_string($secret) && $secret !== '');

        foreach ($secrets as $secret) {
            $text = str_replace($secret, '[redacted]', $text);
        }

        $text = preg_replace(
            [
                '/([?&](?:api_?key|key|token)=)[^&\s]+/i',
                '/(X-(?:Api|Bot)-Key\s*[:=]\s*)[^\s,}\]]+/i',
                '/(Authorization\s*[:=]\s*Bearer\s+)[^\s,}\]]+/i',
            ],
            '$1[redacted]',
            $text,
        ) ?? '[unavailable]';

        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? '[unavailable]';

        return mb_strlen($text) > self::MAX_DIAGNOSTIC_LENGTH
            ? mb_substr($text, 0, self::MAX_DIAGNOSTIC_LENGTH).'…'
            : $text;
    }
}
