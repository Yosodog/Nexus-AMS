<?php

namespace App\Domain\Federation\Contracts;

use App\Domain\Federation\DTO\FederationDiscoveryDocument;
use App\Domain\Federation\DTO\TransportResult;
use App\Domain\Federation\Transport\FederationEndpoint;
use App\Domain\Federation\Transport\PeerOrigin;

interface FederationTransport
{
    public function discover(PeerOrigin $origin): FederationDiscoveryDocument;

    public function send(PeerOrigin $origin, FederationEndpoint $endpoint, string $body): TransportResult;
}
