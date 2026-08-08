<?php

declare(strict_types=1);

namespace App\Services\TenantControl;

use App\Exceptions\TenantControlConfigurationException;

final class TenantControlEndpoint
{
    public function fromConfig(string $key): string
    {
        $url = config($key);

        if (! is_string($url) || $url === '' || strlen($url) > 2_048) {
            throw new TenantControlConfigurationException;
        }

        $parts = parse_url($url);
        $host = is_array($parts) && isset($parts['host']) ? strtolower((string) $parts['host']) : '';

        if (! is_array($parts)
            || ($parts['scheme'] ?? null) !== 'https'
            || $host === ''
            || $host === 'localhost'
            || str_ends_with($host, '.local')
            || filter_var($host, FILTER_VALIDATE_IP) !== false
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])) {
            throw new TenantControlConfigurationException;
        }

        return $url;
    }
}
