<?php

namespace App\Services;

use App\Exceptions\PWRateLimitHitException;
use App\Exceptions\PWQueryFailedException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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
     *
     */
    public function __construct()
    {
        $this->apiKey = $this->getAPIKey();
        $this->endpoint = $this->buildEndpoint();
    }

    /**
     * @return string
     * @throws \Exception
     */
    protected function getAPIKey(): string
    {
        $apiKey = env("PW_API_KEY");

        if (is_null($apiKey))
            throw new \Exception("Env value PW_API_KEY not set");

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
     * @return \stdClass
     * @throws PWQueryFailedException
     * @throws PWRateLimitHitException
     */
    public function sendQuery(GraphQLQueryBuilder $builder, array $variables = [])
    {
        $retryCount = 0;
        $delay = $this->initialDelay;

        // Build the query using the provided GraphQLQueryBuilder
        $query = $builder->build();

        while ($retryCount < $this->maxRetries) {
            $response = Http::post($this->endpoint, [
                'query' => $query,
                'variables' => $variables,
            ]);

            // Check for rate limit (HTTP 429) or errors
            if ($response->status() === 429) {
                // Rate limit hit, implement backoff
                Log::warning('Rate limit hit, retrying in ' . $delay . ' seconds.');
                sleep($delay);
                $retryCount++;
                $delay *= 2; // Exponential backoff
                continue;
            }

            if ($response->successful()) {
                // Return the successful response data
                return $response->json();
            }

            // Handle other errors
            if ($response->failed()) {
                Log::error('Query failed: ' . $response->body());
                throw new PWQueryFailedException('Query failed: ' . $response->body());
            }
        }

        // If we exhausted retries, throw an exception
        throw new PWRateLimitHitException('Rate limit hit too many times, aborting.');
    }
}
