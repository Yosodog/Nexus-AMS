<?php

namespace App\Domain\Federation\Transport;

enum FederationEndpoint: string
{
    case Discovery = '/.well-known/nexus-federation';
    case Handshakes = '/api/v1/federation/handshakes';
    case Envelopes = '/api/v1/federation/envelopes';
}
