<?php

namespace Tests\Unit\Federation;

use App\Domain\Federation\Transport\DnsAddressPolicy;
use App\Domain\Federation\Transport\PeerOrigin;
use InvalidArgumentException;
use Tests\TestCase;

class PeerOriginAndDnsPolicyTest extends TestCase
{
    public function test_origin_is_normalized_and_rejects_ambiguous_or_unsafe_urls(): void
    {
        $this->assertSame('https://peer.example', PeerOrigin::fromUrl('https://PEER.example/')->value());

        foreach ([
            'http://peer.example',
            'https://user@peer.example',
            'https://peer.example/path',
            'https://peer.example?query=1',
            'https://peer.example#fragment',
            'https://127.0.0.1',
            'https://[::1]',
            'https://2130706433',
            'https://0x7f000001',
            'https://peer.example:8443',
        ] as $origin) {
            try {
                PeerOrigin::fromUrl($origin);
                $this->fail("Unsafe origin [{$origin}] was accepted.");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_dns_policy_rejects_private_reserved_metadata_and_mixed_answers(): void
    {
        config()->set('federation.network.allow_private_peers', false);
        $policy = new DnsAddressPolicy;

        foreach ([
            ['127.0.0.1'],
            ['10.0.0.1'],
            ['169.254.169.254'],
            ['::1'],
            ['fc00::1'],
            ['93.184.216.34', '127.0.0.1'],
        ] as $addresses) {
            try {
                $policy->select($addresses);
                $this->fail('Blocked DNS response was accepted.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame('1.1.1.1', $policy->select(['8.8.8.8', '1.1.1.1']));
    }
}
