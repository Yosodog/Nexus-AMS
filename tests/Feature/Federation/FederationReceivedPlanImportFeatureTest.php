<?php

namespace Tests\Feature\Federation;

use App\Domain\Federation\Cryptography\FederationCryptography;
use App\Domain\Federation\DTO\WarPlanSnapshotV1;
use App\Domain\Federation\DTO\WarPlanTargetV1;
use App\Domain\Federation\Enums\CapabilityDirection;
use App\Domain\Federation\Enums\CapabilityState;
use App\Domain\Federation\Enums\CoalitionRole;
use App\Domain\Federation\Enums\CoalitionStatus;
use App\Domain\Federation\Enums\FederationErrorCode;
use App\Domain\Federation\Enums\FederationLinkStatus;
use App\Domain\Federation\Enums\FederationMessageType;
use App\Domain\Federation\Enums\FederationResourceType;
use App\Domain\Federation\Enums\ImportState;
use App\Domain\Federation\Enums\MembershipStatus;
use App\Domain\Federation\Enums\ReceivedDisposition;
use App\Domain\Federation\Enums\ReceivedResourceState;
use App\Domain\Federation\Exceptions\FederationProtocolException;
use App\Domain\Federation\Services\FederatedWarPlanImporter;
use App\Domain\Federation\Services\FederationIdentityService;
use App\Domain\Federation\Services\FederationInboxProcessor;
use App\Domain\Federation\Services\FederationReceivedWarPlanService;
use App\Domain\Federation\Support\Base64Url;
use App\Domain\Milcom\Enums\OperationStatus;
use App\Domain\Milcom\Enums\PriorityTier;
use App\Jobs\DeliverFederationEnvelopeJob;
use App\Jobs\ImportFederatedWarPlanJob;
use App\Models\Alliance;
use App\Models\FederationCapability;
use App\Models\FederationCoalition;
use App\Models\FederationCoalitionMembership;
use App\Models\FederationIdentity;
use App\Models\FederationInboxMessage;
use App\Models\FederationLink;
use App\Models\FederationOutboxMessage;
use App\Models\FederationPeerKey;
use App\Models\FederationReceivedResource;
use App\Models\FederationReceivedVersion;
use App\Models\MilcomObjective;
use App\Models\MilcomOperation;
use App\Models\Nation;
use App\Models\User;
use App\Services\AllianceMembershipService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class FederationReceivedPlanImportFeatureTest extends TestCase
{
    use RefreshDatabase;

    private FederationIdentity $identity;

    private FederationLink $link;

    private FederationPeerKey $remoteKey;

    private string $remoteInstallationId;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.url', 'https://nexus-recipient.example');
        config()->set('federation.enabled', true);
        config()->set('federation.features.inbound', true);
        config()->set('federation.features.linking', true);
        config()->set('federation.features.publishing', true);
        Queue::fake();

        $this->identity = app(FederationIdentityService::class)->enable();
        $this->remoteInstallationId = (string) Str::ulid();
        $this->link = FederationLink::query()->create([
            'id' => (string) Str::ulid(),
            'remote_installation_id' => $this->remoteInstallationId,
            'remote_display_name' => 'Source Nexus',
            'approved_origin' => 'https://nexus-source.example',
            'status' => FederationLinkStatus::Active,
            'remote_ownership_epoch' => 1,
            'negotiated_protocol_version' => '1.0',
            'negotiated_resource_versions' => [
                'milcom.war-plan-snapshot' => ['1.0'],
            ],
            'active_at' => now(),
            'last_contact_at' => now(),
        ]);

        $material = app(FederationCryptography::class)->generateKeyMaterial();
        $this->remoteKey = FederationPeerKey::query()->create([
            'id' => (string) Str::ulid(),
            'federation_link_id' => $this->link->id,
            'remote_key_id' => (string) Str::ulid(),
            'generation' => 1,
            'status' => 'active',
            ...$material,
            'approved_at' => now(),
        ]);
    }

    public function test_inbox_processing_stores_an_exact_pending_review_and_receives_only_a_transport_receipt(): void
    {
        $coalition = $this->createAuthorizedCoalition();
        $snapshot = $this->snapshot($coalition, targetIds: [901001, 901002]);
        $message = $this->inboxMessage($snapshot);

        app(FederationInboxProcessor::class)->process($message);

        $version = FederationReceivedVersion::query()->firstOrFail();
        $resource = FederationReceivedResource::query()->firstOrFail();

        $this->assertSame(ReceivedDisposition::Pending, $version->disposition);
        $this->assertSame(ImportState::NotRequested, $version->import_state);
        $this->assertSame(ReceivedResourceState::PendingReview, $resource->state);
        $this->assertSame($snapshot->toJson(), $version->canonical_payload);
        $this->assertSame($snapshot->hash(), $version->payload_hash);
        $this->assertSame(strlen($snapshot->toJson()), $version->payload_bytes);
        $this->assertSame('processed', $message->fresh()->status->value);
        $this->assertSame(1, FederationReceivedVersion::query()->count());
        $this->assertSame(1, FederationReceivedResource::query()->count());

        $this->assertDatabaseHas('federation_outbox_messages', [
            'message_type' => FederationMessageType::DeliveryReceived->value,
            'recipient_installation_id' => $this->remoteInstallationId,
        ]);
        $this->assertDatabaseMissing('federation_outbox_messages', [
            'message_type' => FederationMessageType::ResourceAcknowledged->value,
        ]);
        $this->assertSame(1, FederationOutboxMessage::query()->count());
        Queue::assertPushed(DeliverFederationEnvelopeJob::class);
    }

    public function test_duplicate_inbox_processing_is_idempotent_and_does_not_create_a_second_receipt_or_version(): void
    {
        $coalition = $this->createAuthorizedCoalition();
        $message = $this->inboxMessage($this->snapshot($coalition));
        $processor = app(FederationInboxProcessor::class);

        $processor->process($message);
        $processor->process($message->fresh());

        $this->assertSame(1, FederationReceivedVersion::query()->count());
        $this->assertSame(1, FederationReceivedResource::query()->count());
        $this->assertSame(1, FederationInboxMessage::query()->where('status', 'processed')->count());
        $this->assertSame(1, FederationOutboxMessage::query()
            ->where('message_type', FederationMessageType::DeliveryReceived->value)
            ->count());
    }

    public function test_receive_revalidates_coalition_membership_and_both_directional_capabilities(): void
    {
        $coalition = $this->createAuthorizedCoalition();
        $snapshot = $this->snapshot($coalition);
        $service = app(FederationReceivedWarPlanService::class);

        $service->store($this->inboxMessage($snapshot), $snapshot->toArray());
        $this->assertSame(1, FederationReceivedVersion::query()->count());

        FederationCapability::query()
            ->where('issuer_installation_id', $this->identity->id)
            ->where('direction', CapabilityDirection::Inbound->value)
            ->update(['state' => CapabilityState::Revoked->value]);
        $secondSnapshot = $this->snapshot($coalition, publicationId: (string) Str::ulid());

        $this->assertProtocolError(
            fn () => $service->store($this->inboxMessage($secondSnapshot), $secondSnapshot->toArray()),
            FederationErrorCode::CapabilityDenied,
        );
    }

    public function test_receive_denies_a_missing_remote_outbound_capability_and_removed_membership(): void
    {
        $coalition = $this->createAuthorizedCoalition();
        $service = app(FederationReceivedWarPlanService::class);
        $snapshot = $this->snapshot($coalition);

        FederationCapability::query()
            ->where('issuer_installation_id', $this->remoteInstallationId)
            ->where('direction', CapabilityDirection::Outbound->value)
            ->update(['state' => CapabilityState::Revoked->value]);

        $this->assertProtocolError(
            fn () => $service->store($this->inboxMessage($snapshot), $snapshot->toArray()),
            FederationErrorCode::CapabilityDenied,
        );

        FederationCapability::query()
            ->where('issuer_installation_id', $this->remoteInstallationId)
            ->where('direction', CapabilityDirection::Outbound->value)
            ->update(['state' => CapabilityState::Active->value]);
        FederationCoalitionMembership::query()
            ->where('federation_coalition_id', $coalition->id)
            ->where('installation_id', $this->remoteInstallationId)
            ->update(['status' => MembershipStatus::Removed->value]);
        $secondSnapshot = $this->snapshot($coalition, publicationId: (string) Str::ulid());

        $this->assertProtocolError(
            fn () => $service->store($this->inboxMessage($secondSnapshot), $secondSnapshot->toArray()),
            FederationErrorCode::MembershipRequired,
        );
    }

    public function test_receive_denies_an_inactive_coalition_even_when_membership_and_capabilities_remain(): void
    {
        $coalition = $this->createAuthorizedCoalition();
        $coalition->forceFill(['status' => CoalitionStatus::Dissolved])->save();
        $snapshot = $this->snapshot($coalition);

        $this->assertProtocolError(
            fn () => app(FederationReceivedWarPlanService::class)->store(
                $this->inboxMessage($snapshot),
                $snapshot->toArray(),
            ),
            FederationErrorCode::CoalitionInactive,
        );
    }

    public function test_acceptance_queues_acknowledgment_and_idempotent_import_creates_one_local_draft(): void
    {
        $user = User::factory()->create();
        $friendlyAlliance = Alliance::factory()->create();
        $target = Nation::factory()->create();
        config()->set('services.pw.alliance_id', $friendlyAlliance->id);
        app(AllianceMembershipService::class)->clear();

        $coalition = $this->createAuthorizedCoalition();
        $snapshot = $this->snapshot($coalition, targetIds: [(int) $target->id]);
        $version = app(FederationReceivedWarPlanService::class)->store(
            $this->inboxMessage($snapshot),
            $snapshot->toArray(),
        );

        $accepted = app(FederationReceivedWarPlanService::class)->accept($version, $user->id);
        $acceptedAgain = app(FederationReceivedWarPlanService::class)->accept($accepted->fresh(), $user->id);

        $this->assertSame(ReceivedDisposition::Accepted, $accepted->disposition);
        $this->assertSame($accepted->id, $acceptedAgain->id);
        $this->assertSame(ImportState::Queued, $accepted->import_state);
        $this->assertSame($user->id, $accepted->reviewed_by);
        Queue::assertPushed(ImportFederatedWarPlanJob::class, function (ImportFederatedWarPlanJob $job) use ($accepted): bool {
            return $job->receivedVersionId === $accepted->id;
        });
        Queue::assertPushed(ImportFederatedWarPlanJob::class, 1);
        $this->assertDatabaseHas('federation_outbox_messages', [
            'message_type' => FederationMessageType::ResourceAcknowledged->value,
            'recipient_installation_id' => $this->remoteInstallationId,
        ]);
        $this->assertSame(1, FederationOutboxMessage::query()
            ->where('message_type', FederationMessageType::ResourceAcknowledged->value)
            ->count());

        $importer = app(FederatedWarPlanImporter::class);
        $imported = $importer->import($accepted);
        $again = $importer->import($imported->fresh());
        $operation = MilcomOperation::query()->findOrFail($imported->imported_operation_id);

        $this->assertSame(ImportState::Imported, $imported->import_state);
        $this->assertSame(ImportState::Imported, $again->import_state);
        $this->assertSame(1, MilcomOperation::query()->count());
        $this->assertSame($operation->id, $again->imported_operation_id);
        $this->assertSame(OperationStatus::Draft, $operation->status);
        $this->assertSame([(int) $target->id], $operation->objectives()->pluck('target_nation_id')->all());
        $this->assertSame($snapshot->recipientInstructions, data_get($operation->metadata, 'federation.recipient_instructions'));
        $this->assertSame(0, $operation->dispatches()->count());
        $this->assertSame(0, $operation->assignmentsThroughObjectives()->count());
    }

    public function test_missing_targets_block_import_before_any_operation_or_objective_is_created(): void
    {
        $user = User::factory()->create();
        $coalition = $this->createAuthorizedCoalition();
        $snapshot = $this->snapshot($coalition, targetIds: [999991]);
        $version = app(FederationReceivedWarPlanService::class)->store(
            $this->inboxMessage($snapshot),
            $snapshot->toArray(),
        );
        $accepted = app(FederationReceivedWarPlanService::class)->accept($version, $user->id);

        $imported = app(FederatedWarPlanImporter::class)->import($accepted);

        $this->assertSame(ImportState::BlockedMissingTargets, $imported->import_state);
        $this->assertSame([999991], $imported->missing_target_ids);
        $this->assertSame('missing_targets', $imported->safe_error_code);
        $this->assertSame(0, MilcomOperation::query()->count());
        $this->assertSame(0, MilcomObjective::query()->count());
    }

    public function test_rejection_purges_the_canonical_payload_and_queues_only_the_redacted_disposition(): void
    {
        $user = User::factory()->create();
        $coalition = $this->createAuthorizedCoalition();
        $snapshot = $this->snapshot($coalition);
        $version = app(FederationReceivedWarPlanService::class)->store(
            $this->inboxMessage($snapshot),
            $snapshot->toArray(),
        );

        $rejected = app(FederationReceivedWarPlanService::class)->reject($version, $user->id);

        $this->assertSame(ReceivedDisposition::Rejected, $rejected->disposition);
        $this->assertSame(ImportState::NotRequested, $rejected->import_state);
        $this->assertNull($rejected->canonical_payload);
        $this->assertNotNull($rejected->payload_purged_at);
        $this->assertSame(ReceivedResourceState::Rejected, $rejected->resource->fresh()->state);
        $this->assertDatabaseHas('federation_outbox_messages', [
            'message_type' => FederationMessageType::ResourceAcknowledged->value,
        ]);
        $this->assertStringNotContainsString(
            $snapshot->title,
            json_encode(FederationOutboxMessage::query()->latest('created_at')->first()->getAttributes(), JSON_THROW_ON_ERROR),
        );
    }

    public function test_revoke_purges_all_received_payloads_and_places_the_imported_operation_under_a_hold(): void
    {
        $user = User::factory()->create();
        $friendlyAlliance = Alliance::factory()->create();
        $target = Nation::factory()->create();
        config()->set('services.pw.alliance_id', $friendlyAlliance->id);
        app(AllianceMembershipService::class)->clear();

        $coalition = $this->createAuthorizedCoalition();
        $snapshot = $this->snapshot($coalition, targetIds: [(int) $target->id]);
        $received = app(FederationReceivedWarPlanService::class);
        $version = $received->store($this->inboxMessage($snapshot), $snapshot->toArray());
        $accepted = $received->accept($version, $user->id);
        $imported = app(FederatedWarPlanImporter::class)->import($accepted);
        $revokeMessage = $this->controlInboxMessage(FederationMessageType::ResourceRevoked, [
            'publication_id' => $snapshot->publicationId,
            'revision' => 2,
            'reason_code' => 'source_revoked',
            'revoked_at' => now()->utc()->toIso8601String(),
        ]);

        $received->revoke($revokeMessage, [
            'publication_id' => $snapshot->publicationId,
            'revision' => 2,
            'reason_code' => 'source_revoked',
            'revoked_at' => now()->utc()->toIso8601String(),
        ], false);

        $version = $version->fresh();
        $resource = $version->resource->fresh();
        $operation = MilcomOperation::query()->findOrFail($imported->imported_operation_id);

        $this->assertNull($version->canonical_payload);
        $this->assertNotNull($version->payload_purged_at);
        $this->assertSame(ReceivedResourceState::Revoked, $resource->state);
        $this->assertSame(2, (int) $resource->current_revision);
        $this->assertTrue((bool) $operation->federation_action_required);
        $this->assertSame('source_revoked', $operation->federation_hold_reason);
    }

    public function test_duplicate_and_lower_revision_snapshots_are_no_ops(): void
    {
        $coalition = $this->createAuthorizedCoalition();
        $service = app(FederationReceivedWarPlanService::class);
        $snapshot = $this->snapshot($coalition, version: 2, revision: 2);
        $message = $this->inboxMessage($snapshot);
        $first = $service->store($message, $snapshot->toArray());
        $duplicate = $service->store($message->fresh(), $snapshot->toArray());
        $lower = $this->snapshot(
            $coalition,
            publicationId: $snapshot->publicationId,
            versionId: (string) Str::ulid(),
            version: 3,
            revision: 1,
        );
        $lowerResult = $service->store($this->inboxMessage($lower), $lower->toArray());

        $this->assertSame($first->id, $duplicate->id);
        $this->assertNull($lowerResult);
        $this->assertSame(1, FederationReceivedVersion::query()->count());
        $this->assertSame(2, (int) FederationReceivedResource::query()->firstOrFail()->current_revision);
    }

    public function test_accepted_pristine_update_rebuilds_the_same_draft_and_marks_the_old_version_stale(): void
    {
        [$user, $target, $coalition, $first, $operation] = $this->importFirstVersion();
        $updatedSnapshot = $this->snapshot(
            $coalition,
            publicationId: $first->source_publication_id,
            version: 2,
            revision: 2,
            targetIds: [(int) $target->id],
            priorityTier: 'critical',
            recipientInstructions: 'Updated instructions',
        );
        $received = app(FederationReceivedWarPlanService::class);
        $second = $received->store($this->inboxMessage($updatedSnapshot), $updatedSnapshot->toArray());
        $second = $received->accept($second, $user->id);
        $updated = app(FederatedWarPlanImporter::class)->import($second);

        $this->assertSame(ImportState::Imported, $updated->import_state);
        $this->assertSame($operation->id, $updated->imported_operation_id);
        $this->assertSame(ImportState::SourceStale, $first->fresh()->import_state);
        $this->assertSame('critical', $operation->fresh()->objectives()->firstOrFail()->priority_tier->value);
        $this->assertSame(
            'Updated instructions',
            data_get($operation->fresh()->metadata, 'federation.recipient_instructions'),
        );
        $this->assertSame(1, MilcomOperation::query()->count());
    }

    public function test_accepted_modified_update_creates_a_new_draft_and_marks_the_old_import_source_stale(): void
    {
        [$user, $target, $coalition, $first, $operation] = $this->importFirstVersion();
        $operation->forceFill([
            'generation_version' => (int) $operation->generation_version + 1,
            'status' => OperationStatus::Review,
        ])->save();
        $updatedSnapshot = $this->snapshot(
            $coalition,
            publicationId: $first->source_publication_id,
            version: 2,
            revision: 2,
            targetIds: [(int) $target->id],
            priorityTier: 'high',
        );
        $received = app(FederationReceivedWarPlanService::class);
        $second = $received->store($this->inboxMessage($updatedSnapshot), $updatedSnapshot->toArray());
        $second = $received->accept($second, $user->id);
        $updated = app(FederatedWarPlanImporter::class)->import($second);

        $this->assertSame(ImportState::Imported, $updated->import_state);
        $this->assertNotSame($operation->id, $updated->imported_operation_id);
        $this->assertSame(ImportState::SourceStale, $first->fresh()->import_state);
        $this->assertSame(2, MilcomOperation::query()->count());
        $this->assertSame(OperationStatus::Review, $operation->fresh()->status);
        $this->assertSame(OperationStatus::Draft, $updated->importedOperation->fresh()->status);
    }

    /** @return array{0: User, 1: Nation, 2: FederationCoalition, 3: FederationReceivedVersion, 4: MilcomOperation} */
    private function importFirstVersion(): array
    {
        $user = User::factory()->create();
        $friendlyAlliance = Alliance::factory()->create();
        $target = Nation::factory()->create();
        config()->set('services.pw.alliance_id', $friendlyAlliance->id);
        app(AllianceMembershipService::class)->clear();

        $coalition = $this->createAuthorizedCoalition();
        $snapshot = $this->snapshot($coalition, targetIds: [(int) $target->id]);
        $received = app(FederationReceivedWarPlanService::class);
        $version = $received->store($this->inboxMessage($snapshot), $snapshot->toArray());
        $accepted = $received->accept($version, $user->id);
        $imported = app(FederatedWarPlanImporter::class)->import($accepted);

        return [$user, $target, $coalition, $imported, $imported->importedOperation->fresh()];
    }

    private function createAuthorizedCoalition(): FederationCoalition
    {
        $coalition = FederationCoalition::factory()->create([
            'coordinator_installation_id' => $this->identity->id,
        ]);
        $coalition->memberships()->createMany([
            [
                'id' => (string) Str::ulid(),
                'installation_id' => $this->identity->id,
                'federation_link_id' => null,
                'role' => CoalitionRole::Coordinator,
                'status' => MembershipStatus::Active,
                'roster_revision' => 1,
                'joined_at' => now(),
            ],
            [
                'id' => (string) Str::ulid(),
                'installation_id' => $this->remoteInstallationId,
                'federation_link_id' => $this->link->id,
                'role' => CoalitionRole::Member,
                'status' => MembershipStatus::Active,
                'roster_revision' => 1,
                'joined_at' => now(),
            ],
        ]);
        FederationCapability::query()->create([
            'id' => (string) Str::ulid(),
            'issuer_installation_id' => $this->identity->id,
            'peer_installation_id' => $this->remoteInstallationId,
            'federation_coalition_id' => $coalition->id,
            'resource_type' => FederationResourceType::WarPlanSnapshot,
            'direction' => CapabilityDirection::Inbound,
            'revision' => 1,
            'state' => CapabilityState::Active,
            'is_local' => true,
            'statement_hash' => hash('sha256', 'recipient-inbound-'.$coalition->id),
            'canonical_statement' => json_encode(['direction' => 'inbound'], JSON_THROW_ON_ERROR),
        ]);
        FederationCapability::query()->create([
            'id' => (string) Str::ulid(),
            'issuer_installation_id' => $this->remoteInstallationId,
            'peer_installation_id' => $this->identity->id,
            'federation_coalition_id' => $coalition->id,
            'resource_type' => FederationResourceType::WarPlanSnapshot,
            'direction' => CapabilityDirection::Outbound,
            'revision' => 1,
            'state' => CapabilityState::Active,
            'is_local' => false,
            'statement_hash' => hash('sha256', 'source-outbound-'.$coalition->id),
            'canonical_statement' => json_encode(['direction' => 'outbound'], JSON_THROW_ON_ERROR),
        ]);

        return $coalition;
    }

    private function snapshot(
        FederationCoalition $coalition,
        ?string $publicationId = null,
        ?string $versionId = null,
        int $version = 1,
        int $revision = 1,
        array $targetIds = [901001],
        string $priorityTier = 'high',
        string $recipientInstructions = 'Coordinate with the local war room.',
    ): WarPlanSnapshotV1 {
        $publishedAt = CarbonImmutable::now('UTC')->subMinute();

        return new WarPlanSnapshotV1(
            publicationId: $publicationId ?? (string) Str::ulid(),
            versionId: $versionId ?? (string) Str::ulid(),
            version: $version,
            revision: $revision,
            sourceInstallationId: $this->remoteInstallationId,
            sourceAllianceId: 5001,
            coalitionId: $coalition->id,
            rosterRevision: (int) $coalition->roster_revision,
            sourceGeneration: 1,
            publishedAt: $publishedAt,
            expiresAt: $publishedAt->addDays(7),
            recipientInstallationId: $this->identity->id,
            title: 'Operation Cedar',
            waveLabel: 'Wave '.$version,
            recipientInstructions: $recipientInstructions,
            targets: array_map(
                fn (int $targetId): WarPlanTargetV1 => new WarPlanTargetV1(
                    targetNationId: $targetId,
                    targetNationName: 'Target '.$targetId,
                    targetAllianceId: 6001,
                    targetAllianceName: 'Source Intelligence',
                    priorityTier: PriorityTier::from($priorityTier),
                    warType: 'ordinary',
                    minimumTeamSize: 1,
                    desiredTeamSize: 2,
                    deadlineAt: $publishedAt->addHours(12),
                ),
                $targetIds,
            ),
        );
    }

    private function inboxMessage(WarPlanSnapshotV1 $snapshot): FederationInboxMessage
    {
        return $this->controlInboxMessage(
            FederationMessageType::ResourcePublished,
            $snapshot->toArray(),
            $snapshot->toJson(),
            WarPlanSnapshotV1::SCHEMA,
        );
    }

    /** @param array<string, mixed> $payload */
    private function controlInboxMessage(
        FederationMessageType $type,
        array $payload,
        ?string $canonicalPayload = null,
        ?string $resourceSchema = null,
    ): FederationInboxMessage {
        $canonicalPayload ??= json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return FederationInboxMessage::query()->create([
            'id' => (string) Str::ulid(),
            'message_id' => (string) Str::ulid(),
            'sender_installation_id' => $this->remoteInstallationId,
            'recipient_installation_id' => $this->identity->id,
            'sender_key_id' => $this->remoteKey->remote_key_id,
            'recipient_key_id' => $this->identity->activeKey->id,
            'nonce' => Base64Url::encode(random_bytes(24)),
            'message_type' => $type,
            'protocol_version' => '1.0',
            'resource_schema' => $resourceSchema,
            'payload_hash' => hash('sha256', $canonicalPayload),
            'envelope_body' => '{}',
            'decrypted_payload' => $canonicalPayload,
            'status' => 'accepted',
            'correlation_id' => (string) Str::ulid(),
            'issued_at' => now(),
            'expires_at' => now()->addDay(),
        ]);
    }

    private function assertProtocolError(callable $callback, FederationErrorCode $expected): void
    {
        try {
            $callback();
            $this->fail('The federation operation unexpectedly succeeded.');
        } catch (FederationProtocolException $exception) {
            $this->assertSame($expected, $exception->errorCode);
        }
    }
}
