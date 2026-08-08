<?php

namespace App\Domain\Federation\Transport;

use App\Domain\Federation\Contracts\DnsResolver;
use RuntimeException;

final class NativeDnsResolver implements DnsResolver
{
    public function resolve(string $hostname): array
    {
        $records = dns_get_record($hostname, DNS_A | DNS_AAAA);

        if (! is_array($records)) {
            throw new RuntimeException('Peer DNS resolution failed.');
        }

        $addresses = [];

        foreach ($records as $record) {
            $address = $record['ip'] ?? $record['ipv6'] ?? null;

            if (is_string($address) && filter_var($address, FILTER_VALIDATE_IP) !== false) {
                $addresses[] = $address;
            }
        }

        $addresses = array_values(array_unique($addresses));

        if ($addresses === []) {
            throw new RuntimeException('Peer DNS resolution returned no addresses.');
        }

        return $addresses;
    }
}
