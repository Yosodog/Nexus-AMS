<?php

namespace App\Domain\Federation\Transport;

use App\Domain\Federation\Contracts\DnsResolver;
use App\Domain\Federation\Contracts\FederationTransport;
use App\Domain\Federation\DTO\FederationDiscoveryDocument;
use App\Domain\Federation\DTO\TransportResult;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\RequestOptions;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

final class DirectHttpTransport implements FederationTransport
{
    public function __construct(
        private readonly DnsResolver $resolver,
        private readonly DnsAddressPolicy $addressPolicy,
    ) {}

    public function discover(PeerOrigin $origin): FederationDiscoveryDocument
    {
        $correlationId = (string) Str::ulid();
        $response = $this->request($origin, $correlationId)
            ->get($origin->endpoint(FederationEndpoint::Discovery));

        if (! $response->successful()) {
            throw new RuntimeException('Federation discovery request failed.');
        }

        $body = $response->body();

        if (strlen($body) > (int) config('federation.limits.outer_request_bytes', 1048576)) {
            throw new RuntimeException('Federation discovery response is too large.');
        }

        $document = FederationDiscoveryDocument::fromJson($body);

        if (! hash_equals($origin->value(), $document->origin)) {
            throw new RuntimeException('Federation discovery origin does not match the requested origin.');
        }

        return $document;
    }

    public function send(PeerOrigin $origin, FederationEndpoint $endpoint, string $body): TransportResult
    {
        if ($endpoint === FederationEndpoint::Discovery) {
            throw new RuntimeException('Discovery does not accept message delivery.');
        }

        if (strlen($body) > (int) config('federation.limits.outer_request_bytes', 1048576)) {
            throw new RuntimeException('Federation envelope is too large.');
        }

        $correlationId = (string) Str::ulid();
        $response = $this->request($origin, $correlationId)
            ->withBody($body, 'application/json')
            ->post($origin->endpoint($endpoint));

        return new TransportResult($response->status(), $response->body(), $correlationId);
    }

    private function request(PeerOrigin $origin, string $correlationId): PendingRequest
    {
        $addresses = $this->resolver->resolve($origin->host);
        $address = $this->addressPolicy->select($addresses);
        $connectAddress = str_contains($address, ':') ? '['.$address.']' : $address;
        $connectRule = $origin->host.':'.$origin->port.':'.$connectAddress.':'.$origin->port;

        return Http::withOptions([
            RequestOptions::ALLOW_REDIRECTS => false,
            RequestOptions::CONNECT_TIMEOUT => (int) config('federation.network.connect_timeout_seconds', 3),
            RequestOptions::TIMEOUT => (int) config('federation.network.request_timeout_seconds', 10),
            RequestOptions::VERIFY => true,
            RequestOptions::PROTOCOLS => ['https'],
            RequestOptions::VERSION => '1.1',
            RequestOptions::CRYPTO_METHOD => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT,
            RequestOptions::HTTP_ERRORS => false,
            RequestOptions::EXPECT => false,
            RequestOptions::COOKIES => false,
            RequestOptions::IDN_CONVERSION => false,
            RequestOptions::PROXY => '',
            RequestOptions::FORCE_IP_RESOLVE => str_contains($address, ':') ? 'v6' : 'v4',
            RequestOptions::CURL => [
                CURLOPT_CONNECT_TO => [$connectRule],
                CURLOPT_FRESH_CONNECT => true,
                CURLOPT_FORBID_REUSE => true,
            ],
        ])->setHandler(new CurlHandler)->withHeaders([
            'Accept' => 'application/json',
            'User-Agent' => 'Nexus-Federation/1.0',
            'X-Nexus-Correlation-ID' => $correlationId,
        ]);
    }
}
