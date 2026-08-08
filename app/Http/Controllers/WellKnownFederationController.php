<?php

namespace App\Http\Controllers;

use App\Domain\Federation\DTO\FederationDiscoveryDocument;
use App\Domain\Federation\Transport\FederationEndpoint;
use App\Models\FederationIdentity;
use Illuminate\Http\JsonResponse;

class WellKnownFederationController extends Controller
{
    public function __invoke(): JsonResponse
    {
        if (! (bool) config('federation.enabled', false)) {
            abort(404);
        }

        $identity = FederationIdentity::query()->with('activeKey')->first();

        if (! $identity instanceof FederationIdentity || ! $identity->enabled || $identity->activeKey === null) {
            abort(404);
        }

        $key = $identity->activeKey;
        $document = new FederationDiscoveryDocument(
            installationId: $identity->id,
            origin: $identity->origin,
            displayName: $identity->display_name,
            ownershipEpoch: (int) $identity->ownership_epoch,
            currentKey: [
                'key_id' => $key->id,
                'generation' => (int) $key->generation,
                'signing_public_key' => $key->signing_public_key,
                'box_public_key' => $key->box_public_key,
                'signing_fingerprint' => $key->signing_fingerprint,
                'box_fingerprint' => $key->box_fingerprint,
            ],
            protocolVersions: [(string) config('federation.protocol_version', '1.0')],
            resourceSchemas: (array) config('federation.resource_schemas', []),
            ingress: [
                'handshakes' => FederationEndpoint::Handshakes->value,
                'envelopes' => FederationEndpoint::Envelopes->value,
            ],
            sizeLimits: [
                'outer_request_bytes' => (int) config('federation.limits.outer_request_bytes', 1048576),
                'decrypted_payload_bytes' => (int) config('federation.limits.decrypted_payload_bytes', 524288),
            ],
        );

        return response()->json($document->toArray())
            ->header('Cache-Control', 'no-store, private');
    }
}
