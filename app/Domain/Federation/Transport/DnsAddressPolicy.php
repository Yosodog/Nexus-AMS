<?php

namespace App\Domain\Federation\Transport;

use InvalidArgumentException;
use Symfony\Component\HttpFoundation\IpUtils;

final class DnsAddressPolicy
{
    /** @var list<string> */
    private const ALWAYS_BLOCKED = [
        '169.254.169.254/32',
        '169.254.170.2/32',
        '100.100.100.200/32',
        '224.0.0.0/4',
        'ff00::/8',
        '64:ff9b::/96',
        '2001:db8::/32',
        '2001::/32',
        '2002::/16',
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
