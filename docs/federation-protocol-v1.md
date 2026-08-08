# Nexus AMS Federation Protocol v1

This document is the implementation reference for direct federation between independent Nexus AMS installations. It describes the wire protocol and the only resource currently supported by protocol v1: `milcom.war-plan-snapshot/1.0`.

Federation is a peer-to-peer Nexus AMS feature. Nexus Cloud may host an installation, but it is not a relay, identity authority, key store, authorization service, or message broker. Nexus AMS installations communicate directly with one another over their configured HTTPS origins. Nexus Discord receives no federation protocol or authorization changes.

Protocol v1 does not provide live collaborative editing, remote mutation of local plans, generic Eloquent sharing, sharing outside a common coalition, participant or assignment sharing, or Discord interaction.

## Scope and gates

The protocol has two independently negotiated version dimensions:

- protocol version: currently `1.0`;
- resource schema versions: currently `milcom.war-plan-snapshot/1.0`.

An installation advertises both dimensions through discovery and the link handshake. A peer must support the local protocol version and the required war-plan schema. Unknown protocol versions and resource schemas are rejected; there is no silent downgrade.

The hard server gate and feature gates are disabled by default:

```dotenv
FEDERATION_ENABLED=false
FEDERATION_INBOUND_ENABLED=false
FEDERATION_LINKING_ENABLED=false
FEDERATION_PUBLISHING_ENABLED=false
FEDERATION_ALLOW_PRIVATE_PEERS=false
```

The hard gate must be enabled before an installation can create its identity. Linking, inbound processing, and publishing remain separate operational decisions. Publishing must stay disabled until the two-installation and security suites pass.

An accepted resource is authorized only when all of these checks succeed at the time it is processed:

1. the bilateral link is active;
2. both installations are active members of the same coalition;
3. the recipient has a current coordinator-signed roster;
4. the source has an active outbound capability for the recipient;
5. the recipient has an active inbound capability for the source;
6. the coalition, membership, link, capabilities, and resource have not expired.

Coalition membership and coalition roles never grant a capability by themselves.

## Public and ingress endpoints

These are the only federation HTTP endpoints. Peer URLs are derived from the origin pinned during linking; a message never supplies an arbitrary delivery URL.

| Method | Path | Purpose | Availability |
| --- | --- | --- | --- |
| `GET` | `/.well-known/nexus-federation` | Public discovery document | Returns `404` unless the server gate and local identity are enabled |
| `POST` | `/api/v1/federation/handshakes` | Encrypted `link.request`, `link.acceptance`, and `link.activation` transport | Handshake rate limit; targeted invitation required |
| `POST` | `/api/v1/federation/envelopes` | All known-peer envelopes after handshake | Inbound gate, peer/link checks, and ingress rate limits |

The admin surface is `/admin/federation`, under the existing authenticated, verified, MFA, and admin middleware. The federation feature does not add a Discord dependency or modify Discord routes.

Discovery contains only:

- installation ULID, approved origin, display name, and ownership epoch;
- the current signing and encryption public keys, key generation, and fingerprints;
- supported protocol versions and resource schema versions;
- the fixed handshake and envelope ingress paths;
- outer-request and decrypted-payload size limits.

Discovery does not disclose coalitions, links, capabilities, publications, received plans, or local policy.

## Exact envelope

The transmitted body is a strict JSON object with exactly four non-empty string properties. Duplicate properties, unknown properties, malformed JSON, and trailing data are rejected.

```json
{
  "version": "1.0",
  "protected": "base64url(fixed-schema JSON header)",
  "ciphertext": "base64url(sealed inner message)",
  "signature": "base64url(Ed25519 detached signature)"
}
```

`base64url` is URL-safe Base64 without padding. The transmitted `protected` and `ciphertext` strings are signed as transmitted. They are not decoded, reserialized, or normalized before signature verification.

### Protected header

The decoded `protected` value is canonical JSON with the following fields:

| Field | Meaning |
| --- | --- |
| `message_id` | Sender-generated ULID. It is the durable idempotency key. |
| `message_type` | One of the message types listed below. |
| `sender_installation_id` | Sender installation ULID. |
| `recipient_installation_id` | Intended recipient installation ULID. |
| `sender_key_id` | Sender signing-key generation ULID. |
| `recipient_key_id` | Recipient encryption-key generation ULID. |
| `issued_at` | UTC ISO-8601 timestamp. |
| `expires_at` | UTC ISO-8601 timestamp after `issued_at`. |
| `nonce` | URL-safe random envelope nonce metadata. |
| `payload_digest` | Lowercase SHA-256 digest of the exact decrypted plaintext bytes. |
| `protocol_version` | The negotiated protocol version, currently `1.0`. |
| `resource_schema` | `milcom.war-plan-snapshot/1.0` only for published or updated snapshots; otherwise absent. |
| `handshake_signing_public_key` | Present only on the initial `link.request`, so the recipient can verify a peer that has no link yet. |

The header itself is fixed-schema JSON. Unknown properties are rejected. IDs are ULIDs, timestamps are parsed as timestamps, and the recipient/key IDs must match the local installation and selected key generation.

The decrypted payload is the canonical JSON bytes whose digest appears in `payload_digest`. Canonical JSON sorts object keys recursively, preserves list order, uses unescaped slashes and Unicode, and preserves zero fractions. Resource and control DTOs then validate the payload with strict field allowlists. Lists and nested objects are validated independently.

### Signature input

The detached Ed25519 signature is over this exact domain-separated, length-prefixed UTF-8 byte sequence:

```text
nexus-federation-envelope-v1
version:<strlen(version)>:<version>
protected:<strlen(protected)>:<protected>
ciphertext:<strlen(ciphertext)>:<ciphertext>
```

The four lines are joined with `\n`; the lengths are byte lengths, not character counts. The signature is verified with the approved peer signing key using constant-time cryptographic verification. Each recipient gets an independently sealed ciphertext, so two recipients never share one encrypted payload just because they received the same plan.

### Verification order and limits

Implementations should follow this order:

1. reject a body larger than `1 MiB` before JSON decoding;
2. parse the exact outer envelope and reject unknown or duplicate properties;
3. require the configured protocol version (`1.0`);
4. parse and validate the protected header, recipient, key, timestamps, and resource schema;
5. verify the detached signature over the transmitted strings;
6. decrypt with the recipient X25519 box private key;
7. reject plaintext over `512 KiB`;
8. compare the payload digest against the exact plaintext bytes;
9. parse strict JSON and validate the message DTO;
10. apply peer, link, coalition, capability, revision, replay, and expiry rules;
11. store the validated inbox message durably before reporting transport acceptance.

Future-dated messages beyond the five-minute clock-skew allowance are rejected. Expired messages are rejected. A sender/message identifier and sender/key/nonce combination is unique in storage, so replayed messages are no-ops or receive `message_replayed` rather than producing another side effect. Protocol v1 has no compression.

HTTP `202 Accepted` means only that the envelope was durably accepted by transport. It does not mean the recipient verified or decrypted it, and it is not a human acknowledgment. A validated recipient may send `delivery.received`; the recipient's administrator later sends `resource.acknowledged` for a human disposition. Acknowledgments are not acknowledged recursively.

## Cryptography and fingerprints

Nexus uses the PHP sodium extension already required by the application:

- Ed25519 key pairs (`sodium_crypto_sign_keypair`) are used only for detached signatures;
- X25519 box key pairs (`sodium_crypto_box_keypair`) are separate encryption keys;
- each recipient is encrypted independently with `sodium_crypto_box_seal`;
- private signing and box keys are encrypted at rest using Laravel encrypted casts and are hidden from model serialization;
- no additional cryptography dependency is used.

A fingerprint is the uppercase SHA-256 digest, grouped in four-character blocks with hyphens, over:

```text
<algorithm>\0<purpose>\0<raw public key bytes>
```

The signing fingerprint uses `Ed25519` / `signing`; the encryption fingerprint uses `X25519` / `encryption`. Compare both complete fingerprints out of band. A public key is not trusted merely because it arrived in a discovery response or a later message.

## Message catalog

Every message type has a strict DTO. Unknown top-level or nested properties are rejected. The following field lists are the protocol-v1 contract; fields described as optional are still schema-known and must use the documented type.

| Message type | Required payload shape and purpose |
| --- | --- |
| `link.request` | `invitation_id`, `invitation_token`, `source_origin`, `source_display_name`, `source_installation_id`, `source_ownership_epoch`, `source_key`, `supported_protocol_versions`, `resource_schemas`, `expires_at`. The key object contains key ID, generation, signing/encryption public keys, and both fingerprints. The invitation token is one-time and is never stored in plaintext. |
| `link.acceptance` | `invitation_id`, `invitation_token`, `source_installation_id`, `recipient_origin`, `recipient_display_name`, `recipient_installation_id`, `recipient_ownership_epoch`, `recipient_key`, `supported_protocol_versions`, `resource_schemas`, `accepted_at`. |
| `link.activation` | `invitation_id`, `invitation_token`, `link_id`, `source_installation_id`, `recipient_installation_id`, `activated_at`, `acknowledgment` (boolean). The first activation has `false`; the response has `true`. |
| `installation.key_rotation` | `installation_id`, `ownership_epoch`, `old_key_id`, `new_key`, `statement`, `old_signature`, `new_signature`, `issued_at`. The old and new signing keys both sign the same canonical statement. |
| `installation.endpoint_change` | `proposal_id`, `installation_id`, `old_origin`, `new_origin`, `ownership_epoch`, `status`, `issued_at`, `expires_at`. The new origin is not pinned until the bilateral workflow is approved and activated. |
| `link.suspension_notice` | `link_id`, `reason_code`, `suspended_at`. It communicates local suspension without exposing resource content. |
| `capability.manifest` | `issuer_installation_id`, `generated_at`, and `statements`. Each statement contains `peer_installation_id`, `coalition_id`, `resource_type`, `direction`, `revision`, `state`, `expires_at`, and `statement_hash`. |
| `coalition.invitation` | `action`, `invitation_id`, `invitation_token`, `coalition_id`, `coalition_name`, `coordinator_installation_id`, `role`, `roster_revision`, `expires_at`, and `acted_at`. `roster_revision` and `acted_at` follow the nullable rules of the DTO. |
| `coalition.proposal` | `proposal_id`, `coalition_id`, `proposal_type`, `workflow_key`, `target_installation_id`, `requested_role`, `base_roster_revision`, `payload_hash`, and `expires_at`. A proposal response uses the same strict shape with an action-suffixed proposal type. |
| `coalition.manifest` | `coalition_id`, `name`, `coordinator_installation_id`, `revision`, `status`, `expires_at`, `manifest_hash`, and `members`. Each member has installation ID, role, status, joined/expiry timestamps, and optional removal timestamp. |
| `coalition.dissolved` | `coalition_id`, `revision`, `dissolved_at`, and `manifest_hash`. |
| `resource.published` / `resource.updated` | The complete `WarPlanSnapshotV1` payload described below. The protected header must carry `milcom.war-plan-snapshot/1.0`. |
| `resource.acknowledged` | `publication_id`, `version_id`, `version`, `revision`, `disposition`, and `acknowledged_at`. Disposition is a human `accepted` or `rejected` state. |
| `resource.access_revoked` | `publication_id`, `recipient_installation_id`, `revision`, `reason_code`, and `revoked_at`. It is a recipient-specific tombstone. |
| `resource.revoked` | `publication_id`, `revision`, `reason_code`, and `revoked_at`. It is a publication-wide tombstone. |
| `delivery.received` | `original_message_id` and `received_at`. It reports validated durable storage only. |
| `reconciliation.manifest` | `generated_at` and `resources`. Each resource contains type, ID, highest revision, hash, state, expiry, and a list of missing version numbers. It never contains full resource content. |

Message-specific authorization is applied after schema validation. For example, a syntactically valid capability statement from an unknown sender is still rejected, and a valid war-plan snapshot without current coalition and capability authorization is not stored as an accepted resource.

## Version, revision, and error semantics

Protocol negotiation is independent from resource negotiation. The link request and acceptance include the sender's supported protocol list and resource-schema map. The current installation advertises only the values in `config/federation.php` and records the common resource versions on the link.

Resource revisions are monotonically increasing. Duplicate or lower revisions are no-ops. A same-revision/different-hash message is a conflict. A revocation is a higher-revision tombstone. Once a publication ID is revoked, it can never be resurrected; republish as a new publication ID. Expiry is enforced locally even if the peer is offline.

Stable error codes are safe for operators and retries:

`invalid_envelope`, `invalid_signature`, `recipient_mismatch`, `message_replayed`, `message_expired`, `unknown_peer`, `link_inactive`, `coalition_inactive`, `membership_required`, `capability_denied`, `protocol_unsupported`, `schema_unsupported`, `payload_too_large`, `version_conflict`, `rate_limited`, and `temporary_unavailable`.

Malformed, unauthorized, incompatible, and oversized messages are non-retryable until state or configuration changes. Connection failures, `408`, `429`, and `5xx` responses are transient and are retried with bounded exponential backoff and jitter until message expiry. Error records contain only a safe error code and correlation ID; they never contain keys, ciphertext, decrypted content, titles, targets, or instructions.

## Direct HTTPS and SSRF requirements

The production transport is direct HTTPS. It derives the discovery, handshake, and envelope paths from the stored `PeerOrigin` and disables redirects. Before every request and retry it resolves the hostname again, validates every returned address, selects one validated address, and pins that address for the connection while retaining hostname/SNI certificate verification.

Peer origins must:

- use HTTPS and the configured port (`443` in production);
- contain a DNS hostname, not an IP literal or encoded numeric address;
- contain no userinfo, query, fragment, path, trailing dot, whitespace, or percent encoding;
- resolve to public addresses immediately before the request;
- use a certificate valid for the hostname;
- never follow redirects, use a proxy, send cookies, or accept an arbitrary peer-supplied URL.

The resolver rejects loopback, private, link-local, multicast, reserved, documentation, benchmark, carrier-grade NAT, NAT64/Teredo/6to4, and cloud metadata-service addresses. Mixed DNS answers are rejected if any returned address is blocked. DNS is re-resolved on every retry to reduce rebinding exposure.

`FEDERATION_ALLOW_PRIVATE_PEERS=true` is honored only in local/testing application environments for controlled test harnesses. It can permit private addresses and local HTTP/port 80 there, but IP literals and always-blocked/reserved metadata ranges remain invalid. Do not enable this override in production.

Ingress applies body-size checks before decoding and global, source-IP, and declared-sender rate limits. Current defaults are 120 global requests/minute, 30 requests/minute per IP, 60 requests/minute per declared sender, and 10 handshake requests/minute per IP. Error responses are intentionally generic and do not reveal whether a private resource exists.

## War-plan snapshot schema

The only resource adapter in v1 is the dedicated `WarPlanSnapshotV1` DTO. Eloquent models, current Milcom serializers, and Discord serializers are never sent over federation.

The top-level canonical payload contains exactly these fields:

`schema`, `publication_id`, `version_id`, `version`, `revision`, `source_installation_id`, `source_alliance_id`, `coalition_id`, `roster_revision`, `source_generation`, `published_at`, `expires_at`, `recipient_installation_id`, `title`, `wave_label`, `recipient_instructions`, and `targets`.

Each target contains exactly:

`target_nation_id`, `target_nation_name`, `target_alliance_id`, `target_alliance_name`, `priority_tier`, `war_type`, `minimum_team_size`, `desired_team_size`, and `deadline_at`.

The schema enforces ULIDs and positive identifiers, unique target nation IDs, one to 500 targets, supported war types, team sizes from one to six, no `hold` priority, and recipient instructions no longer than 1,000 characters. The normal UI expiry is seven days; the maximum accepted expiry is 30 days.

The following are deliberately excluded:

- friendly alliances, friendly nations, and all participants;
- assignments, assignment states, declared wars, attack progress, deliveries, and Discord identifiers;
- numeric threat or match scores, confidence, recommendations, alternatives, warnings, factors, and override reasons;
- military, readiness, resource, activity, project, or other source-internal operational data;
- the existing `war_reason`, which is never copied automatically;
- Discord rooms, mentions, tags, links, and internal URLs;
- creator IDs, local record IDs, metadata, failures, event payloads, and implementation details.

The publication preview stores the exact per-recipient canonical payload bytes, byte count, and SHA-256 hash. Publishing creates one independently encrypted envelope per recipient. The operation generation, selected objectives, recipients, coalition, capabilities, and preview hash are rechecked at publish time. Any change invalidates the preview and requires a new immutable version.

Source changes are never auto-published. An update is an explicit new version. An access revoke is recipient-specific; a full revoke sends publication tombstones to every recipient.

## Delivery and reconciliation

Outbox rows and their control/resource messages are created in the same database transaction. Dispatch occurs after commit. A unique delivery job, a once-per-minute outbox sweeper, idempotent inbox processing, and a 15-minute per-link reconciliation job provide at-least-once delivery without assuming exactly-once HTTP behavior.

Reconciliation manifests exchange only resource IDs, types, highest revisions, hashes, states, expiry, and missing version numbers. A peer resends a missing unexpired version or a tombstone. Reconciliation never bypasses link, coalition, capability, or expiry checks and never resurrects a revoked publication.

The configured retention periods are 30 days for processed message bodies and 180 days for revocation/audit tombstones. Local expiry remains authoritative even while the other installation is offline.
