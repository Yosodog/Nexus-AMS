<?php

namespace Tests\Unit\Federation;

use App\Domain\Federation\Contracts\DnsResolver;
use App\Domain\Federation\DTO\FederationDiscoveryDocument;
use App\Domain\Federation\Transport\DirectHttpTransport;
use App\Domain\Federation\Transport\DnsAddressPolicy;
use App\Domain\Federation\Transport\FederationEndpoint;
use App\Domain\Federation\Transport\PeerOrigin;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class DirectHttpTransportTest extends TestCase
{
    public function test_discovery_uses_the_fixed_well_known_endpoint_and_validates_the_origin(): void
    {
        Http::preventStrayRequests();
        $origin = PeerOrigin::fromUrl('https://peer.example.test');
        Http::fake([
            $origin->endpoint(FederationEndpoint::Discovery) => Http::response(
                $this->discoveryDocument($origin->value()),
            ),
        ]);

        $document = $this->transport()->discover($origin);

        $this->assertInstanceOf(FederationDiscoveryDocument::class, $document);
        $this->assertSame($origin->value(), $document->origin);
        Http::assertSent(fn (Request $request): bool => $request->url() === $origin->endpoint(
            FederationEndpoint::Discovery,
        ));
    }

    public function test_send_uses_the_fixed_envelope_endpoint_and_pins_one_dns_answer(): void
    {
        Http::preventStrayRequests();
        $origin = PeerOrigin::fromUrl('https://peer.example.test');
        $capturedOptions = [];
        Http::fake(function (Request $request, array $options) use (&$capturedOptions): object {
            $capturedOptions = $options;

            return Http::response(['accepted' => true], 202);
        });

        $result = $this->transport()->send($origin, FederationEndpoint::Envelopes, '{"message":"test"}');

        $this->assertSame(202, $result->status);
        $this->assertNotSame('', $result->correlationId);
        $this->assertFalse($capturedOptions['allow_redirects']);
        $this->assertTrue($capturedOptions['verify']);
        $this->assertSame(3, $capturedOptions['connect_timeout']);
        $this->assertSame(10, $capturedOptions['timeout']);
        $this->assertSame('', $capturedOptions['proxy']);
        $this->assertSame(
            ['peer.example.test:443:93.184.216.34:443'],
            $capturedOptions['curl'][CURLOPT_CONNECT_TO],
        );
        Http::assertSent(function (Request $request) use ($origin): bool {
            return $request->url() === $origin->endpoint(FederationEndpoint::Envelopes)
                && $request->body() === '{"message":"test"}'
                && $request->header('Content-Type')[0] === 'application/json';
        });
    }

    public function test_discovery_origin_mismatch_is_rejected_without_following_redirects(): void
    {
        Http::preventStrayRequests();
        $origin = PeerOrigin::fromUrl('https://peer.example.test');
        Http::fake([
            $origin->endpoint(FederationEndpoint::Discovery) => Http::response(
                $this->discoveryDocument('https://another.example.test'),
            ),
        ]);

        $this->expectException(RuntimeException::class);
        $this->transport()->discover($origin);
    }

    public function test_oversized_peer_response_is_rejected_by_the_bounded_stream_reader(): void
    {
        Http::preventStrayRequests();
        config()->set('federation.limits.outer_request_bytes', 64);
        $origin = PeerOrigin::fromUrl('https://peer.example.test');
        Http::fake([
            $origin->endpoint(FederationEndpoint::Discovery) => Http::response(str_repeat('x', 65)),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('response is too large');
        $this->transport()->discover($origin);
    }

    public function test_blocked_dns_answers_are_rejected_before_http_is_sent(): void
    {
        Http::preventStrayRequests();
        $origin = PeerOrigin::fromUrl('https://peer.example.test');
        Http::fake();

        try {
            $this->transport(['93.184.216.34', '127.0.0.1'])->discover($origin);
            $this->fail('A mixed public and loopback DNS response was accepted.');
        } catch (InvalidArgumentException $exception) {
            $this->assertMatchesRegularExpression('/blocked|non-public/', strtolower($exception->getMessage()));
        }

        Http::assertNothingSent();
    }

    public function test_oversized_envelopes_are_rejected_before_dns_or_http_access(): void
    {
        Http::preventStrayRequests();
        config()->set('federation.limits.outer_request_bytes', 8);
        Http::fake();
        $resolverCalled = false;
        $resolver = new class($resolverCalled) implements DnsResolver
        {
            public function __construct(private bool &$called) {}

            public function resolve(string $hostname): array
            {
                $this->called = true;

                return ['93.184.216.34'];
            }
        };

        try {
            $this->makeTransport($resolver)->send(
                PeerOrigin::fromUrl('https://peer.example.test'),
                FederationEndpoint::Envelopes,
                '123456789',
            );
            $this->fail('An oversized envelope was accepted.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('too large', strtolower($exception->getMessage()));
        }

        $this->assertFalse($resolverCalled);
        Http::assertNothingSent();
    }

    public function test_discovery_cannot_be_used_as_a_delivery_endpoint(): void
    {
        Http::preventStrayRequests();
        Http::fake();

        try {
            $this->transport()->send(
                PeerOrigin::fromUrl('https://peer.example.test'),
                FederationEndpoint::Discovery,
                '{}',
            );
            $this->fail('Discovery was accepted as a delivery endpoint.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('does not accept', strtolower($exception->getMessage()));
        }

        Http::assertNothingSent();
    }

    /** @param list<string> $addresses */
    private function transport(array $addresses = ['93.184.216.34']): DirectHttpTransport
    {
        $resolver = new class($addresses) implements DnsResolver
        {
            /** @param list<string> $addresses */
            public function __construct(private array $addresses) {}

            public function resolve(string $hostname): array
            {
                return $this->addresses;
            }
        };

        return $this->makeTransport($resolver);
    }

    private function makeTransport(DnsResolver $resolver): DirectHttpTransport
    {
        config()->set('federation.network.allow_private_peers', false);

        return new DirectHttpTransport($resolver, new DnsAddressPolicy);
    }

    /** @return array<string, mixed> */
    private function discoveryDocument(string $origin): array
    {
        return [
            'installation_id' => (string) Str::ulid(),
            'origin' => $origin,
            'display_name' => 'Peer Nexus',
            'ownership_epoch' => 1,
            'current_key' => [
                'key_id' => (string) Str::ulid(),
                'generation' => 1,
                'signing_public_key' => 'signing-public-key',
                'box_public_key' => 'box-public-key',
                'signing_fingerprint' => str_repeat('A', 64),
                'box_fingerprint' => str_repeat('B', 64),
            ],
            'supported_protocol_versions' => ['1.0'],
            'resource_schemas' => [
                'milcom.war-plan-snapshot' => ['1.0'],
            ],
            'ingress' => [
                'handshakes' => '/api/v1/federation/handshakes',
                'envelopes' => '/api/v1/federation/envelopes',
            ],
            'size_limits' => [
                'outer_request_bytes' => 1048576,
                'decrypted_payload_bytes' => 524288,
            ],
        ];
    }
}
