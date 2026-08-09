<?php

namespace App\Domain\Federation\Transport;

use InvalidArgumentException;
use Symfony\Component\HttpFoundation\IpUtils;

final class DnsAddressPolicy
{
    /** @var list<string> */
    private const ALWAYS_BLOCKED = [
        '100.64.0.0/10',
        '100.100.100.200/32',
        '169.254.0.0/16',
        '192.0.0.0/24',
        '192.0.2.0/24',
        '192.88.99.0/24',
        '198.18.0.0/15',
        '198.51.100.0/24',
        '203.0.113.0/24',
        '224.0.0.0/4',
        '240.0.0.0/4',
        '::/128',
        '::ffff:0:0/96',
        '100::/64',
        '64:ff9b::/96',
        '2001::/23',
        '2001:2::/48',
        '2001:db8::/32',
        '2002::/16',
        '3fff::/20',
        'ff00::/8',
    ];

    /** @param  list<string>  $addresses */
    public function select(array $addresses): string
    {
        if ($addresses === []) {
            throw new InvalidArgumentException('Peer DNS resolution returned no addresses.');
        }

        $allowPrivate = app()->environment(['local', 'testing'])
            && (bool) config('federation.network.allow_private_peers', false);

        foreach ($addresses as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP) === false
                || IpUtils::checkIp($address, self::ALWAYS_BLOCKED)) {
                throw new InvalidArgumentException('Peer DNS resolution returned a blocked address.');
            }

            $isPublic = filter_var(
                $address,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            ) !== false;

            if (! $isPublic && ! $allowPrivate) {
                throw new InvalidArgumentException('Peer DNS resolution returned a non-public address.');
            }
        }

        sort($addresses, SORT_STRING);

        return $addresses[0];
    }
}
