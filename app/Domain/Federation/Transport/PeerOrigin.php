<?php

namespace App\Domain\Federation\Transport;

use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class PeerOrigin
{
    private function __construct(
        public string $scheme,
        public string $host,
        public int $port,
    ) {}

    public static function fromUrl(string $url): self
    {
        if ($url === '' || preg_match('/[\x00-\x20\x7f]/', $url) === 1 || str_contains($url, '%')) {
            throw new InvalidArgumentException('Peer origin is malformed.');
        }

        $parts = parse_url($url);

        if (! is_array($parts)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])) {
            throw new InvalidArgumentException('Peer origin may not contain credentials, a query, or a fragment.');
        }

        $scheme = Str::lower((string) ($parts['scheme'] ?? ''));
        $host = Str::lower((string) ($parts['host'] ?? ''));
        $path = (string) ($parts['path'] ?? '');
        $allowLocalHttp = app()->environment(['local', 'testing'])
            && (bool) config('federation.network.allow_private_peers', false);

        if ($scheme !== 'https' && ! ($allowLocalHttp && $scheme === 'http')) {
            throw new InvalidArgumentException('Peer origin must use HTTPS.');
        }

        if ($path !== '' && $path !== '/') {
            throw new InvalidArgumentException('Peer origin must not include a path.');
        }

        if ($host === ''
            || str_ends_with($host, '.')
            || filter_var($host, FILTER_VALIDATE_IP) !== false
            || preg_match('/^(?:0x[0-9a-f]+|[0-9]+)(?:\.(?:0x[0-9a-f]+|[0-9]+))*$/iD', $host) === 1) {
            throw new InvalidArgumentException('Peer origin must use a DNS hostname.');
        }

        if (function_exists('idn_to_ascii')) {
            $asciiHost = idn_to_ascii($host, IDNA_USE_STD3_RULES | IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);

            if (! is_string($asciiHost)) {
                throw new InvalidArgumentException('Peer origin hostname is invalid.');
            }

            $host = Str::lower($asciiHost);
        }

        if (strlen($host) > 253 || preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $host) !== 1) {
            throw new InvalidArgumentException('Peer origin hostname is invalid.');
        }

        $port = isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80);
        $allowedPorts = array_map('intval', (array) config('federation.network.allowed_ports', [443]));

        if (! in_array($port, $allowedPorts, true) && ! ($allowLocalHttp && $port === 80)) {
            throw new InvalidArgumentException('Peer origin port is not supported.');
        }

        return new self($scheme, $host, $port);
    }

    public function value(): string
    {
        $defaultPort = $this->scheme === 'https' ? 443 : 80;

        return $this->scheme.'://'.$this->host.($this->port === $defaultPort ? '' : ':'.$this->port);
    }

    public function endpoint(FederationEndpoint $endpoint): string
    {
        return $this->value().$endpoint->value;
    }
}
