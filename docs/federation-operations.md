# Nexus AMS Federation Operator Runbook

This runbook covers setup, linking, governance, war-plan exchange, incident response, and routine maintenance for Nexus AMS federation. It assumes the direct protocol described in [Federation Protocol v1](federation-protocol-v1.md).

Federation is implemented in Nexus AMS. Nexus Cloud only hosts an installation and has no federation database, relay, key, routing, or authorization responsibility. Nexus Discord is not changed and does not receive federation payloads or commands.

## Before enabling an installation

Federation is deliberately disabled by default. Deploy the schema and code while every gate remains off. Validate the complete two-installation and security suites before enabling publishing.

### Network and application prerequisites

Each participating installation needs:

1. a stable public DNS hostname and routable HTTPS origin;
2. a valid TLS certificate for that hostname;
3. `APP_URL` set to that origin, without a path;
4. inbound access to the fixed federation endpoints;
5. PHP's sodium extension;
6. a stable application encryption key and protected database backups;
7. queue workers and the Laravel scheduler running continuously;
8. a database with the federation migrations applied.

Production installations cannot use private/NAT-only origins in protocol v1 unless operators provide their own routable network path that still satisfies the direct HTTPS and DNS policy. Do not use `FEDERATION_ALLOW_PRIVATE_PEERS` in production.

### Configuration

Set these environment values in deployment configuration; do not commit them to `.env` files:

| Environment variable | Default | Use |
| --- | --- | --- |
| `FEDERATION_ENABLED` | `false` | Hard availability gate. Must be true before an identity can be generated. |
| `FEDERATION_INBOUND_ENABLED` | `false` | Allows normal inbound federation processing. |
| `FEDERATION_LINKING_ENABLED` | `false` | Allows discovery and link workflows. |
| `FEDERATION_PUBLISHING_ENABLED` | `false` | Allows war-plan preview and publication. Keep off until final acceptance. |
| `FEDERATION_ALLOW_PRIVATE_PEERS` | `false` | Local/testing-only private-peer override. Never enable in production. |

The corresponding application config is `config/federation.php`. Important fixed defaults are:

| Setting | Default |
| --- | ---: |
| Protocol version | `1.0` |
| Resource schema | `milcom.war-plan-snapshot/1.0` |
| HTTPS | required |
| Allowed production port | `443` |
| Connect/request timeout | `3s` / `10s` |
| Invitation expiry | `24 hours` |
| Default publication expiry | `7 days` |
| Maximum publication expiry | `30 days` |
| Maximum outer request | `1 MiB` |
| Maximum decrypted payload | `512 KiB` |
| Maximum targets per publication | `500` |
| Recipient instructions | `1,000` characters |
| Allowed clock skew | `5 minutes` |
| Reconciliation interval | `15 minutes` |
| Processed-body retention | `30 days` |
| Tombstone/audit retention | `180 days` |
| Retiring-key grace period | `30 days` |

Rate limits default to 120 requests/minute globally, 30/minute per IP, 60/minute per declared sender, and 10 handshake requests/minute per IP.

### Initial deployment sequence

1. Deploy migrations and code with all federation environment flags false.
2. Restart queue workers after deployment and confirm the scheduler is running.
3. Set `FEDERATION_ENABLED=true` on two non-production installations; leave linking and publishing off until the network checks pass.
4. Enable the identity from `/admin/federation` on each installation. The first enable operation creates one installation ULID and the first signing/encryption key generation transactionally. Enabling again reuses the same identity.
5. Confirm each installation's origin, installation ID, ownership epoch, signing fingerprint, and encryption fingerprint.
6. Enable `FEDERATION_LINKING_ENABLED=true` and complete a bilateral link with out-of-band fingerprint comparison.
7. Create a coalition, add the linked installation, and configure explicit capabilities.
8. Enable inbound processing and validate receive-only behavior, acknowledgments, expiry, and revocation.
9. Enable publishing for a test coalition only after the two-installation and security suites pass.
10. Expand one installation at a time while watching queue age, reconciliation, quarantined inbox, and held-operation health checks.

Disabling federation from the admin page disables the local identity but retains identity, keys, links, publications, received history, and audit history. That retention is intentional for recovery and forensics.

## Permissions and admin surface

The federation destination appears in Admin Settings for users with `view-federation`. The complete workflow is at `/admin/federation`.

| Permission | Scope |
| --- | --- |
| `view-federation` | View identity, links, coalitions, publications, received plans, and the federation page. |
| `manage-federation` | Enable/disable identity, link or suspend peers, approve workflows, rotate or compromise keys, and manage endpoints. |
| `manage-coalitions` | Create coalitions, invite/remove members, review proposals, manage roles, transfer coordination, and dissolve coalitions. |
| `publish-federated-war-plans` | Preview and publish snapshots. The action also requires `manage-war-room`. |
| `review-federated-war-plans` | Review, accept, or reject received snapshots. |
| `import-federated-war-plans` | Retry imports and resolve holds. Import, detach, and retire actions also require `manage-war-room`. |
| `view-federation-diagnostics` | View queue ages, retries, safe error codes, reconciliation, compatibility, key age, quarantine, and held-operation signals. |

The protected default-admin role receives these permissions through the existing permission seeder. Server-side authorization remains authoritative even if a button is hidden.

## Bilateral linking

Linking is a two-installation workflow. Link activation does not grant any resource capability.

### Source administrator

1. Open `/admin/federation` and use **Fetch public fingerprints**.
2. Enter the peer's base HTTPS origin, such as `https://peer.example.org`.
3. Confirm that discovery returns the expected installation ID, origin, display name, ownership epoch, protocol/resource versions, and both public-key fingerprints.
4. Compare the signing and encryption fingerprints with the peer administrator through a separate trusted channel. Compare the complete grouped strings, not a shortened display.
5. Check **I compared both fingerprints** and send the targeted invitation.

The invitation expires after 24 hours and the token is stored only as a SHA-256 hash. Discovery never changes an existing pinned origin automatically.

### Recipient administrator

1. Open the pending link approval from the Federation page or staff work queue.
2. Compare the source installation ID, origin, and both fingerprints against the trusted out-of-band values.
3. Approve the incoming request. This sends `link.acceptance` with the recipient's own public keys and version support.

### Final activation

1. The source administrator reviews the acceptance and performs the final approval.
2. Nexus sends `link.activation`.
3. The recipient stores the activation and sends the acknowledgment activation.
4. Both installations show the link as `active`.

Link states are `pending_remote`, `pending_local`, `active`, `suspended`, `revoked`, and `expired`. A suspension blocks normal resource traffic but still permits known-resource revocations, key/endpoint recovery, suspension notices, reconciliation control, and delivery receipts as applicable. Revocation is terminal; reconnecting requires a new link workflow.

## Key rotation and compromise

Keys have no fixed expiry. Rotation is manual or incident-driven. Public key metadata remains available for audit after a private key is retired.

### Routine rotation

1. A user with `manage-federation` selects **Rotate keys**.
2. Nexus creates a new pending signing/encryption generation.
3. The old signing key and the new signing key cross-sign the same canonical rotation statement.
4. Nexus queues `installation.key_rotation` to each active peer.
5. Each peer verifies the old and new signatures, key material, fingerprints, ownership epoch, and installation ID, then acknowledges the new generation.
6. Activate the pending key only after every active peer has acknowledged it.
7. The old key becomes `retiring` and remains available while messages encrypted to it can still expire. The configured 30-day grace period then permits private material to be purged; public metadata is retained.

Do not replace an active key by editing the database or by accepting a discovery change. A missing old key or an unapproved key change suspends the link and requires fingerprint reapproval.

### Suspected compromise

1. Mark the affected generation **Compromised** immediately from `/admin/federation`.
2. Nexus removes local private material for that generation and suspends active and pending-local links.
3. Generate the replacement pending generation.
4. Reapprove peer fingerprints out of band and complete the mutual recovery workflow.
5. If the old key is unavailable, do not trust a rotation signed only by it. Re-link the installations using a new invitation and out-of-band fingerprint verification.

Ownership transfer increments the ownership epoch and follows routine rotation while the old key remains available. If it is unavailable, peers must be relinked rather than trusting an unanchored update.

## Pinned endpoint changes

An endpoint change is a signed bilateral proposal, not a discovery side effect.

1. The proposing administrator submits a new DNS HTTPS origin from the link controls.
2. Nexus validates the origin and queues `installation.endpoint_change`.
3. The remote administrator verifies the proposed origin through a trusted channel and approves or rejects it.
4. The proposing side performs activation after remote approval.
5. The new origin becomes the stored pinned origin only after the workflow completes.

The same origin restrictions apply to a proposed endpoint: DNS hostname, HTTPS, no path/query/fragment/userinfo, supported port, valid certificate, and public DNS resolution. If discovery reports a changed origin, leave the stored origin unchanged until a signed endpoint workflow is approved. An unexpected key or endpoint change should suspend the link and be investigated as a possible compromise.

## Coalition governance and recovery

### Normal governance

1. The creator installation becomes the coalition coordinator and issues roster revision 1.
2. The coordinator invites only installations with active bilateral links.
3. The invited administrator accepts locally.
4. The coordinator issues the next canonical, signed roster manifest.
5. Every member verifies the coordinator installation, manifest hash, monotonic revision, status, membership, roles, and expiry before projecting the roster.

Roles are `coordinator`, `admin`, `member`, and `observer`. `member` and `observer` do not imply access. A remote `admin` can submit a signed roster proposal, but a coordinator-side administrator must approve it before a new roster is issued. Add, remove, and role-change proposals are explicit and use a pending workflow key, so duplicate approvals cannot be created concurrently.

Coordinator transfer requires the current coordinator's approval and destination acceptance. Use the **Accept transfer** and **Approve transfer** actions; do not manually edit a roster. After completion, the destination becomes coordinator and the former coordinator becomes an admin. If the current coordinator key is unavailable, do not accept stale manifests or invent a replacement signature: create a new coalition, relink/reapprove the members, and recreate capabilities.

Removal, dissolution, and expiry are security events. Removing a member expires coalition-scoped capabilities and causes each source installation to revoke its outstanding publications to that member. Coalition expiry or dissolution invalidates all coalition-scoped capabilities and received relationships.

### Explicit capability matrix

Capabilities are per peer, per coalition, per resource, per direction, and per revision. The only resource in v1 is `milcom.war-plan-snapshot`.

- `outbound`: local officers may publish the resource to the named peer;
- `inbound`: this installation will accept offers of the resource from the named peer;
- `active`, `revoked`, and `expired` are distinct states;
- an optional capability expiry is enforced locally;
- a newer revision supersedes an older revision;
- setting a capability never creates coalition membership and membership never creates a capability.

For a source to publish, configure the source's outbound capability and the recipient's inbound capability. For a recipient to accept, revalidate the same two directional statements at receive time. A coalition template may create individual statements, but it is never a wildcard.

### Coalition recovery checklist

If a roster, coordinator, or membership appears stale:

1. suspend the affected link if the peer identity, key, or endpoint cannot be verified;
2. inspect the last accepted roster revision and hash in `/admin/federation`;
3. do not accept a lower revision or a same-revision manifest with a different hash;
4. allow reconciliation to resend missing manifests while the link remains known;
5. if the coordinator key is unavailable, create a replacement coalition and reapprove each member rather than bypassing coordinator authorization;
6. recreate only the explicit capabilities that are still needed;
7. review outstanding publications and revoke any access that should no longer exist;
8. resolve or retire imported operations placed under federation action required.

## Publishing a war-plan snapshot

Publishing is an officer-controlled, exact-preview workflow.

An initial publication requires federation and publishing gates, `publish-federated-war-plans`, `manage-war-room`, a plan operation with committed scope and objectives, no generating/completed/archived/failed/finalized/dispatching state, selected open objectives, and a valid recipient for every selected member installation. Hold, blocked, cancelled, expired, and completed objectives are rejected.

From `/admin/federation`:

1. select an eligible operation and the exact objectives;
2. select recipients who pass the coalition, link, and two-way capability checks;
3. enter the share-specific title, wave label, recipient instructions, and expiry;
4. inspect the exact canonical JSON for every recipient, each byte count, each SHA-256 hash, recipient IDs, expiry, and the excluded-category list;
5. publish only if the preview is correct.

The publish action rechecks operation generation, selected targets, recipients, coalition, capabilities, and preview hash inside a transaction. If any of those values or the exact canonical bytes changed, the preview is stale and publishing is rejected. An update is a new immutable version; it is never an implicit source-side mutation.

The snapshot includes only the fields in `WarPlanSnapshotV1`: source and coalition identifiers/revisions, source generation and timestamps, one recipient, share title/wave label, recipient instructions, and per-target nation/alliance display hints, priority, war type, team sizes, and deadline. It excludes friendly scope, participants, assignments, declared wars, progress, recommendations, scores, military/readiness/resource/activity/project data, existing war reasons, Discord data, creator/local IDs, internal metadata, failures, event payloads, and internal URLs.

## Receiving, reviewing, and importing

### Delivery states

Treat these as separate facts:

1. **Transport accepted:** the peer stored the envelope durably and returned `202`.
2. **Validated/stored:** the recipient verified, decrypted, schema-checked, authorized, and stored the snapshot.
3. **Human disposition:** an authorized reviewer accepted or rejected it.

The staff work queue exposes pending link approvals, coalition workflows, received reviews, blocked imports, and held operations. The Federation page shows the source installation, pinned fingerprint, coalition/roster revision, version/freshness/expiry, exact fields, and version diff without exposing source-internal data.

### Accept

Acceptance requires `review-federated-war-plans`. It records `accepted`, queues an idempotent import, and sends `resource.acknowledged`. The importer:

- uses all locally configured alliance IDs as friendly scope;
- imports the exact target nation IDs and does not expand enemy alliances;
- creates a local draft through `OperationService`;
- applies shared priority, war type, minimum/desired team depth, and deadlines;
- retains the local default war reason;
- stores source publication/version/coalition provenance and recipient instructions as federation context;
- creates no recommendations, assignments, Discord rooms, or deliveries.

If any target nation is missing locally, the acceptance still stands but import becomes `blocked_missing_targets`. No partial operation is created. Synchronize nations, then use **Retry import**.

### Reject

Rejecting requires `review-federated-war-plans`, sends a signed rejected acknowledgment, queues it durably, and purges the decrypted payload. The recipient retains only the source/publication/version/hash/timestamps and disposition needed for audit and reconciliation.

### Updates

An accepted update is handled as follows:

- if the prior imported operation is still a draft, its generation matches the recorded import baseline, and it has no recommendations, assignments, dispatches, assignment deliveries, or Discord rooms, the same draft is rebuilt from the new version;
- otherwise the new version creates a new local draft and the previous import is marked `source_stale`;
- staffed or dispatched local work is never overwritten.

The source receives only the recipient installation's accepted/rejected disposition. It does not receive local user identity, assignments, import details, or Discord data.

## Revocation, expiry, and hard holds

The following events revoke remote trust or freshness and can place linked local operations under `federation_action_required`:

- publication or recipient-specific access revocation;
- resource expiry;
- coalition removal, expiry, or dissolution;
- capability revocation or expiry;
- link recovery/security invalidation;
- a received revocation tombstone.

On revocation or expiry, Nexus purges the received decrypted payload, records a redacted tombstone, stops future remote updates, and holds every linked imported operation, including active operations. Draft and active operations are both covered.

While held, the operation cannot be edited, recommended, approved, assigned, activated, dispatched, delivered, cloned, or automatically mutated by lifecycle reconciliation. Route middleware and Milcom service guards enforce the hold. Pending unleased Discord commands are suppressed, and queued in-game assignment jobs recheck before sending. An already-leased external action cannot necessarily be recalled; that fact is recorded without adding its content to federation logs.

### Resolve a hold

An authorized officer needs `import-federated-war-plans` and `manage-war-room`.

**Continue independently**

- provide a confirmation reason between 10 and 1,000 characters;
- permanently detach the operation from the remote publication/version;
- clear the hold and remote-update relationship;
- retain current local targets and assignments;
- treat later peer updates as irrelevant.

**Retire local operation**

- provide the same reason and permissions;
- use the existing complete/archive lifecycle to release unfinished assignments and archive rooms;
- if engaged wars prevent completion, leave the operation held until they finish or choose Continue independently.

Neither action restores a purged remote payload. Detachment is a local decision and does not tell the source who made local assignments or how the local operation was resolved.

## Reliability and health

The transport is at-least-once. The durable outbox, unique delivery job, inbox processor, and recovery sweeper make lost queue dispatches and lost HTTP responses safe to retry. Retryable network failures, `408`, `429`, and `5xx` responses use bounded exponential backoff with jitter. Expired messages stop retrying.

The scheduled federation jobs are:

| Job | Schedule | Purpose |
| --- | --- | --- |
| `SweepFederationOutboxJob` | Every minute | Dispatch due outbox rows and recover accepted inbox processing. |
| `ReconcileFederationLinksJob` | Every 15 minutes | Exchange per-link manifests and resend missing versions/tombstones. |
| `ExpireFederationResourcesJob` | Every 5 minutes | Expire workflows, coalitions, capabilities, publications, resources, messages, and retire old local keys. |
| `PruneFederationMessagesJob` | Daily at `02:30` | Prune bodies and old transport records according to retention. |

All federation schedules use `withoutOverlapping()` and `onOneServer()`. Confirm queue and scheduler freshness after deployment. `/admin/federation#diagnostics` exposes payload-free pending outbox count/age, unprocessed inbox age, quarantine count, and safe correlation/error information for users with `view-federation-diagnostics`. System health also reports identity/configuration validity, scheduler freshness, stale links, pending changes, and held imported operations.

Useful operator distinctions:

- an old pending outbox row means transport work has not completed;
- a transport-accepted row means the peer accepted storage, not human review;
- a quarantined inbox row needs a safe error-code investigation;
- a pending review is a human workflow, not a network failure;
- a blocked import normally means nation synchronization is incomplete;
- a held operation requires an officer resolution, not a retry.

## Data classification and audit rules

Treat federation data as restricted operational information:

| Class | Examples | Handling |
| --- | --- | --- |
| Public discovery metadata | Installation ID, origin, display name, ownership epoch, public keys/fingerprints, supported versions, fixed paths, size limits | Safe for discovery, but changes still require verification and audit. |
| Restricted peer policy | Link state, coalition roster, roles, capabilities, publication IDs, revisions, hashes, dispositions, safe error codes | Share only with the named peer or authorized local staff; never expose through discovery. |
| Confidential operational data | War-plan targets, display hints, priorities, team sizes, deadlines, recipient instructions, and decrypted snapshots | Encrypt per recipient, show only to authorized reviewers, purge on rejection/revocation/expiry, and exclude from logs. |
| Critical secret material | Private signing and box keys, invitation tokens before hashing, decrypted key material | Encrypted at rest, hidden from serialization, never logged or returned in diagnostics. |

Audit uses the existing `AuditLogger`. Audit contexts may contain installation, publication, coalition, version, revision, hash, state change, reason code, correlation ID, and actor ID. They must not contain payload fields, titles, targets, instructions, keys, invitation tokens, ciphertext, or decrypted bodies. Health views and exception reports follow the same rule.

Purging removes the local decrypted payload, not the fact that a message was received or that a disposition/revocation occurred. An authorized recipient may already have viewed, copied, screenshotted, or acted on data before a purge. Federation cannot erase information that a recipient has already seen; operators must treat publication as disclosure to every authorized recipient.

## Rollback and incident response

For a controlled rollback, disable linking and publishing first. Keep known-peer ingress available only when it is intentionally needed for receipts, revocations, and recovery control; do not use a hard disable if those messages must still be processed. Retain federation tables and tombstones and deploy forward fixes rather than rolling back populated migrations.

If a peer reports an invalid signature, unknown key, endpoint mismatch, or unexpected roster change:

1. suspend the link locally;
2. preserve the safe correlation ID and error code;
3. compare installation IDs, ownership epoch, and both fingerprints out of band;
4. inspect pending endpoint/key workflows and the last accepted roster hash/revision;
5. declare a key compromise if private material may have been exposed;
6. revoke affected publications or coalition access as appropriate;
7. resolve any resulting imported-operation holds through the explicit detach/retire workflow.

Do not retry a malformed or unauthorized envelope indefinitely. Retry only after the underlying link, key, endpoint, capability, or configuration state has changed.

Common first actions by safe error code:

| Error | Operator action |
| --- | --- |
| `invalid_envelope`, `invalid_signature`, `recipient_mismatch` | Quarantine, verify peer/key/recipient IDs, and investigate; do not retry blindly. |
| `message_replayed` | Confirm the original message was already processed; no manual re-import is needed. |
| `message_expired` | Generate a new version or use a new workflow; expired content is not resurrected. |
| `unknown_peer`, `link_inactive` | Review link state and re-link if the link was revoked. |
| `coalition_inactive`, `membership_required`, `capability_denied` | Restore the explicit coalition/capability workflow before resending. |
| `protocol_unsupported`, `schema_unsupported` | Upgrade/configure both installations; there is no silent downgrade. |
| `payload_too_large` | Reduce the selected resource within schema limits; never raise limits casually. |
| `version_conflict` | Stop and compare hashes/revisions; do not force a lower or same-revision different payload. |
| `rate_limited`, `temporary_unavailable` | Allow bounded retry/backoff and inspect peer health. |
