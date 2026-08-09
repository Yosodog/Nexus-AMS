<?php

namespace Tests\Feature\Federation;

use App\Domain\Federation\Cryptography\FederationCryptography;
use App\Domain\Federation\DTO\FederationEnvelope;
use App\Domain\Federation\Enums\CapabilityDirection;
use App\Domain\Federation\Enums\CapabilityState;
use App\Domain\Federation\Enums\CoalitionRole;
use App\Domain\Federation\Enums\DeliveryState;
use App\Domain\Federation\Enums\FederationErrorCode;
use App\Domain\Federation\Enums\FederationKeyStatus;
use App\Domain\Federation\Enums\FederationLinkStatus;
use App\Domain\Federation\Enums\FederationMessageType;
use App\Domain\Federation\Enums\FederationResourceType;
use App\Domain\Federation\Enums\MembershipStatus;
use App\Domain\Federation\Enums\OutboxStatus;
use App\Domain\Federation\Enums\PublicationStatus;
use App\Domain\Federation\Exceptions\FederationProtocolException;
use App\Domain\Federation\Services\FederationCoalitionService;
use App\Domain\Federation\Services\FederationIdentityService;
use App\Domain\Federation\Services\FederationReconciliationService;
use App\Domain\Federation\Services\WarPlanPublicationService;
use App\Domain\Federation\Support\CanonicalJson;
use App\Domain\Federation\Support\StrictJson;
use App\Domain\Milcom\Enums\ObjectiveStatus;
use App\Domain\Milcom\Enums\OperationStatus;
use App\Domain\Milcom\Enums\PriorityTier;
use App\Http\Controllers\Admin\FederationPublicationController;
use App\Http\Requests\Admin\FederationPublicationPreviewRequest;
use App\Models\Alliance;
use App\Models\FederationCapability;
use App\Models\FederationCoalition;
use App\Models\FederationIdentity;
use App\Models\FederationLink;
use App\Models\FederationOutboxMessage;
use App\Models\FederationPeerKey;
use App\Models\FederationPublication;
use App\Models\FederationPublicationDelivery;
use App\Models\FederationPublicationVersion;
use App\Models\MilcomOperation;
use App\Models\Nation;
use App\Models\User;
use App\Services\AllianceMembershipService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Milcom\Concerns\BuildsMilcomFixtures;
use Tests\TestCase;

class FederationWarPlanPublicationFeatureTest extends TestCase
{
    use BuildsMilcomFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.url', 'https://nexus-one.example');
        config()->set('federation.enabled', true);
        config()->set('federation.features.inbound', true);
        config()->set('federation.features.linking', true);
        config()->set('federation.features.publishing', true);
        Queue::fake();
        Http::preventStrayRequests();
    }

    public function test_initial_preview_requires_a_committed_plan_open_objectives_common_coalition_and_both_capabilities(): void
    {
        $fixture = $this->publicationFixture(1);
        $service = app(WarPlanPublicationService::class);

        $fixture['operation']->forceFill(['current_stage' => 'scope'])->save();
        $exception = $this->assertValidationFailure(fn () => $this->preview($fixture));
        $this->assertArrayHasKey('operation', $exception->errors());

        $fixture['operation']->forceFill(['current_stage' => 'staffing'])->save();
        $fixture['objective']->forceFill(['priority_tier' => PriorityTier::Hold])->save();
        $exception = $this->assertValidationFailure(fn () => $this->preview($fixture));
        $this->assertArrayHasKey('objectives', $exception->errors());

        $fixture['objective']->forceFill(['priority_tier' => PriorityTier::High])->save();
        FederationCapability::query()
            ->where('issuer_installation_id', $fixture['links'][0]->remote_installation_id)
            ->where('peer_installation_id', $fixture['identity']->id)
            ->where('direction', CapabilityDirection::Inbound->value)
            ->delete();

        try {
            $service->preview(...$this->previewArguments($fixture));
            $this->fail('A missing recipient inbound capability must reject an initial preview.');
        } catch (FederationProtocolException $exception) {
            $this->assertSame(FederationErrorCode::CapabilityDenied, $exception->errorCode);
        }

        $fixture['coalition']->memberships()
            ->where('installation_id', $fixture['links'][0]->remote_installation_id)
            ->delete();

        try {
            $service->preview(...$this->previewArguments($fixture));
            $this->fail('A recipient outside the common coalition must reject an initial preview.');
        } catch (FederationProtocolException $exception) {
            $this->assertSame(FederationErrorCode::MembershipRequired, $exception->errorCode);
        }
    }

    public function test_preview_bytes_and_hashes_equal_the_published_plaintext_and_each_recipient_gets_distinct_ciphertext(): void
    {
        $fixture = $this->publicationFixture(2);
        $version = $this->preview($fixture);
        $preview = json_decode((string) $version->canonical_preview, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(hash('sha256', (string) $version->canonical_preview), $version->preview_hash);

        $published = app(WarPlanPublicationService::class)->publish($version, $version->preview_hash);

        $this->assertSame('published', $published->status);
        $this->assertSame(PublicationStatus::Published, $published->publication->fresh()->status);
        $this->assertSame(2, FederationOutboxMessage::query()
            ->where('message_type', FederationMessageType::ResourcePublished->value)
            ->count());

        $ciphertexts = [];
        $messageIds = [];

        foreach ($published->fresh('deliveries')->deliveries as $delivery) {
            $previewRecipient = collect($preview['recipients'])
                ->firstWhere('recipient_installation_id', $delivery->recipient_installation_id);

            $this->assertIsArray($previewRecipient);
            $this->assertSame($previewRecipient['payload'], $delivery->canonical_payload);
            $this->assertSame($previewRecipient['payload_hash'], $delivery->payload_hash);
            $this->assertSame($previewRecipient['payload_bytes'], $delivery->payload_bytes);
            $this->assertSame(hash('sha256', $delivery->canonical_payload), $delivery->payload_hash);
            $this->assertSame(strlen($delivery->canonical_payload), $delivery->payload_bytes);

            $outbox = FederationOutboxMessage::query()
                ->where('message_id', $delivery->outbox_message_id)
                ->firstOrFail();
            $messageIds[] = $outbox->message_id;
            $envelope = FederationEnvelope::fromJson((string) $outbox->envelope_body);
            $peer = $fixture['peers'][$delivery->recipient_installation_id];
            $plaintext = app(FederationCryptography::class)->open(
                $envelope->ciphertext,
                $peer['material']['box_public_key'],
                $peer['material']['box_private_key'],
            );

            $this->assertSame($delivery->canonical_payload, $plaintext);
            $ciphertexts[] = $envelope->ciphertext;
        }

        $this->assertCount(2, array_unique($ciphertexts));
        $this->assertCount(2, array_unique($messageIds));
    }

    public function test_stale_source_generation_rejects_publishing_an_existing_preview(): void
    {
        $fixture = $this->publicationFixture(1);
        $version = $this->preview($fixture);

        $fixture['operation']->forceFill([
            'generation_version' => (int) $fixture['operation']->generation_version + 1,
        ])->save();

        $exception = $this->assertValidationFailure(
            fn () => app(WarPlanPublicationService::class)->publish($version, $version->preview_hash),
        );

        $this->assertArrayHasKey('preview', $exception->errors());
        $this->assertSame('preview', $version->fresh()->status);
        $this->assertSame(0, FederationOutboxMessage::query()->count());
    }

    public function test_reconciliation_never_advertises_or_resends_an_unpublished_preview(): void
    {
        $fixture = $this->publicationFixture(1);
        $this->preview($fixture);
        $link = $fixture['links'][0];

        app(FederationReconciliationService::class)->send($link);

        $outbox = FederationOutboxMessage::query()
            ->where('message_type', FederationMessageType::ReconciliationManifest->value)
            ->firstOrFail();
        $envelope = FederationEnvelope::fromJson((string) $outbox->envelope_body);
        $peer = $fixture['peers'][$link->remote_installation_id];
        $plaintext = app(FederationCryptography::class)->open(
            $envelope->ciphertext,
            $peer['material']['box_public_key'],
            $peer['material']['box_private_key'],
        );

        $this->assertIsString($plaintext);
        $this->assertSame([], StrictJson::decodeObject($plaintext)['resources']);
        $this->assertSame(0, FederationOutboxMessage::query()
            ->whereIn('message_type', [
                FederationMessageType::ResourcePublished->value,
                FederationMessageType::ResourceUpdated->value,
            ])
            ->count());
    }

    public function test_stale_target_selection_rejects_publishing_an_existing_preview(): void
    {
        $fixture = $this->publicationFixture(1);
        $version = $this->preview($fixture);
        $replacementTarget = Nation::factory()->create();

        $fixture['objective']->forceFill(['target_nation_id' => $replacementTarget->id])->save();

        $exception = $this->assertValidationFailure(
            fn () => app(WarPlanPublicationService::class)->publish($version, $version->preview_hash),
        );

        $this->assertArrayHasKey('objectives', $exception->errors());
        $this->assertSame(0, FederationOutboxMessage::query()->count());
    }

    public function test_stale_directional_capability_rejects_publishing_an_existing_preview(): void
    {
        $fixture = $this->publicationFixture(1);
        $version = $this->preview($fixture);

        FederationCapability::query()
            ->where('issuer_installation_id', $fixture['identity']->id)
            ->where('peer_installation_id', $fixture['links'][0]->remote_installation_id)
            ->where('direction', CapabilityDirection::Outbound->value)
            ->firstOrFail()
            ->forceFill(['state' => CapabilityState::Revoked])
            ->save();

        try {
            app(WarPlanPublicationService::class)->publish($version, $version->preview_hash);
            $this->fail('A revoked outbound capability must reject a stale preview.');
        } catch (FederationProtocolException $exception) {
            $this->assertSame(FederationErrorCode::CapabilityDenied, $exception->errorCode);
        }

        $this->assertSame(0, FederationOutboxMessage::query()->count());
    }

    public function test_updates_are_allowed_after_finalization_but_a_new_initial_publication_is_not(): void
    {
        $fixture = $this->publicationFixture(1);
        $operation = $fixture['operation'];
        $operation->forceFill([
            'metadata' => [...($operation->metadata ?? []), 'finalized_at' => now()->utc()->toIso8601String()],
        ])->save();

        $exception = $this->assertValidationFailure(fn () => $this->preview($fixture));
        $this->assertArrayHasKey('operation', $exception->errors());

        $operation->forceFill([
            'metadata' => collect($operation->metadata ?? [])->except('finalized_at')->all(),
        ])->save();
        $initial = $this->preview($fixture);
        $publication = app(WarPlanPublicationService::class)->publish($initial, $initial->preview_hash)->publication;

        $operation->forceFill([
            'metadata' => [...($operation->metadata ?? []), 'finalized_at' => now()->utc()->toIso8601String()],
        ])->save();
        $update = $this->preview($fixture, $publication, 'Updated wave');

        $this->assertSame(2, $update->version);
        $publishedUpdate = app(WarPlanPublicationService::class)->publish($update, $update->preview_hash);
        $this->assertSame('published', $publishedUpdate->status);
        $this->assertSame(2, (int) $publication->fresh()->current_version);
        $this->assertSame(1, FederationOutboxMessage::query()
            ->where('message_type', FederationMessageType::ResourceUpdated->value)
            ->count());

        $this->assertValidationFailure(fn () => $this->preview($fixture));
    }

    public function test_publication_revocation_is_monotonic_for_one_recipient_then_all_recipients(): void
    {
        $fixture = $this->publicationFixture(2);
        $version = $this->preview($fixture);
        $publication = app(WarPlanPublicationService::class)->publish($version, $version->preview_hash)->publication;

        app(WarPlanPublicationService::class)->revokeRecipient(
            publication: $publication,
            recipientInstallationId: $fixture['links'][0]->remote_installation_id,
            reasonCode: 'operator_revoke',
            actorUserId: $fixture['actor']->id,
        );

        $partial = $publication->fresh();
        $this->assertSame(PublicationStatus::PartiallyRevoked, $partial->status);
        $this->assertSame(2, $partial->current_revision);
        $revokedDelivery = $partial->versions()->with('deliveries')->firstOrFail()->deliveries
            ->firstWhere('recipient_installation_id', $fixture['links'][0]->remote_installation_id);
        $this->assertSame(DeliveryState::Revoked, $revokedDelivery->state);
        $this->assertNull($revokedDelivery->canonical_payload);
        $this->assertSame(2, $revokedDelivery->access_revocation_revision);
        $this->assertSame(
            OutboxStatus::Failed,
            FederationOutboxMessage::query()
                ->where('message_id', $revokedDelivery->outbox_message_id)
                ->value('status'),
        );
        $this->assertNull(FederationOutboxMessage::query()
            ->where('message_id', $revokedDelivery->outbox_message_id)
            ->value('envelope_body'));

        app(WarPlanPublicationService::class)->revokeAll(
            publication: $partial,
            reasonCode: 'operator_revoke_all',
            actorUserId: $fixture['actor']->id,
        );

        $revoked = $publication->fresh();
        $this->assertSame(PublicationStatus::Revoked, $revoked->status);
        $this->assertSame(3, $revoked->current_revision);
        $this->assertSame(2, $revoked->versions()->with('deliveries')->firstOrFail()->deliveries
            ->where('state', DeliveryState::Revoked)->count());
        $this->assertSame(1, FederationOutboxMessage::query()
            ->where('message_type', FederationMessageType::ResourceAccessRevoked->value)
            ->count());
        $this->assertSame(2, FederationOutboxMessage::query()
            ->where('message_type', FederationMessageType::ResourceRevoked->value)
            ->count());
    }

    public function test_publishing_an_update_revokes_removed_recipients_and_the_publication_cannot_restore_them(): void
    {
        $fixture = $this->publicationFixture(2);
        $initial = $this->preview($fixture);
        $publication = app(WarPlanPublicationService::class)
            ->publish($initial, $initial->preview_hash)
            ->publication;
        $removedLink = $fixture['links'][1];
        $fixture['links'] = [$fixture['links'][0]];
        $update = $this->preview($fixture, $publication, 'Reduced recipient update');

        app(WarPlanPublicationService::class)->publish($update, $update->preview_hash);

        $this->assertSame(PublicationStatus::PartiallyRevoked, $publication->fresh()->status);
        $this->assertSame(3, $publication->fresh()->current_revision);
        $this->assertSame(1, FederationOutboxMessage::query()
            ->where('message_type', FederationMessageType::ResourceAccessRevoked->value)
            ->where('recipient_installation_id', $removedLink->remote_installation_id)
            ->count());
        $this->assertTrue(FederationPublicationDelivery::query()
            ->where('recipient_installation_id', $removedLink->remote_installation_id)
            ->get()
            ->every(fn (FederationPublicationDelivery $delivery): bool => $delivery->state === DeliveryState::Revoked
                && $delivery->canonical_payload === null));

        $fixture['links'] = [$removedLink];
        $exception = $this->assertValidationFailure(
            fn () => $this->preview($fixture, $publication, 'Attempted recipient restoration'),
        );
        $this->assertArrayHasKey('recipients', $exception->errors());
    }

    public function test_admin_publication_preview_requires_both_publication_and_war_room_permissions(): void
    {
        $version = FederationPublicationVersion::factory()->create();

        $publishOnly = $this->grantPermissions(
            $this->createVerifiedAdmin(['nation_id' => 940301]),
            ['publish-federated-war-plans'],
        );
        $this->attachDiscordAccount($publishOnly);
        $this->assertPublicationPreviewForbidden($publishOnly, $version);

        $warRoomOnly = $this->grantPermissions(
            $this->createVerifiedAdmin(['nation_id' => 940302]),
            ['manage-war-room'],
        );
        $this->attachDiscordAccount($warRoomOnly);
        $this->assertPublicationPreviewForbidden($warRoomOnly, $version);

        $both = $this->grantPermissions(
            $this->createVerifiedAdmin(['nation_id' => 940303]),
            ['publish-federated-war-plans', 'manage-war-room'],
        );
        $this->attachDiscordAccount($both);
        $this->assertTrue($both->can('publish-federated-war-plans'));
        $this->assertTrue($both->can('manage-war-room'));

        $request = FederationPublicationPreviewRequest::create('/admin/federation/publications/preview', 'POST');
        $request->setUserResolver(fn (): object => $both);
        $this->assertTrue($request->authorize());
    }

    /** @param array<string, mixed> $fixture */
    private function preview(
        array $fixture,
        ?FederationPublication $publication = null,
        string $title = 'Operation Northstar',
    ): FederationPublicationVersion {
        return app(WarPlanPublicationService::class)->preview(
            ...$this->previewArguments($fixture, $publication, $title),
        );
    }

    /**
     * @param  array<string, mixed>  $fixture
     * @return array{0: MilcomOperation, 1: FederationCoalition, 2: list<string>, 3: list<int>, 4: string, 5: string, 6: string, 7: CarbonImmutable, 8: int, 9: ?FederationPublication}
     */
    private function previewArguments(
        array $fixture,
        ?FederationPublication $publication = null,
        string $title = 'Operation Northstar',
    ): array {
        return [
            $fixture['operation'],
            $fixture['coalition'],
            collect($fixture['links'])->pluck('id')->all(),
            [$fixture['objective']->id],
            $title,
            'Wave 1',
            'Coordinate through the receiving staff queue.',
            CarbonImmutable::now('UTC')->addDays(2),
            $fixture['actor']->id,
            $publication,
        ];
    }

    /** @param array<string, mixed> $fixture */
    private function assertValidationFailure(callable $callback): ValidationException
    {
        try {
            $callback();
        } catch (ValidationException $exception) {
            return $exception;
        }

        $this->fail('Expected a validation exception.');
    }

    private function assertPublicationPreviewForbidden(
        User $user,
        FederationPublicationVersion $version,
    ): void {
        $this->actingAs($user);

        try {
            app(FederationPublicationController::class)->showPreview($version);
            $this->fail('Both publication and war-room permissions are required.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }
    }

    /** @return array<string, mixed> */
    private function publicationFixture(int $recipientCount): array
    {
        $actor = $this->createVerifiedAdmin(['nation_id' => fake()->unique()->numberBetween(940400, 940499)]);
        $identity = app(FederationIdentityService::class)->enable();
        $sourceAlliance = Alliance::factory()->create();
        $targetAlliance = Alliance::factory()->create();
        config()->set('services.pw.alliance_id', $sourceAlliance->id);
        app(AllianceMembershipService::class)->clear();

        $operation = $this->createMilcomOperation([
            'created_by' => $actor->id,
            'status' => OperationStatus::Review,
            'current_stage' => 'staffing',
            'generation_version' => 1,
        ]);
        $target = Nation::factory()->create(['alliance_id' => $targetAlliance->id]);
        $objective = $this->createMilcomObjective($operation, $target, [
            'priority_tier' => PriorityTier::High,
            'status' => ObjectiveStatus::Review,
            'minimum_team_depth' => 1,
            'desired_team_depth' => 2,
            'deadline_at' => now()->addDay(),
        ]);
        $coalition = app(FederationCoalitionService::class)
            ->create('Operations', null, $actor->id);
        $links = [];
        $peers = [];

        for ($index = 0; $index < $recipientCount; $index++) {
            $remoteInstallationId = (string) Str::ulid();
            $material = app(FederationCryptography::class)->generateKeyMaterial();
            $link = FederationLink::query()->create([
                'id' => (string) Str::ulid(),
                'remote_installation_id' => $remoteInstallationId,
                'remote_display_name' => 'Peer Nexus '.($index + 1),
                'approved_origin' => 'https://peer-'.($index + 1).'.example',
                'status' => FederationLinkStatus::Active,
                'remote_ownership_epoch' => 1,
                'negotiated_protocol_version' => '1.0',
                'negotiated_resource_versions' => ['milcom.war-plan-snapshot' => ['1.0']],
                'active_at' => now(),
            ]);
            $peerKey = FederationPeerKey::query()->create([
                'id' => (string) Str::ulid(),
                'federation_link_id' => $link->id,
                'remote_key_id' => (string) Str::ulid(),
                'generation' => 1,
                'status' => FederationKeyStatus::Active,
                ...collect($material)->except(['signing_private_key', 'box_private_key'])->all(),
                'approved_at' => now(),
            ]);
            $coalition->memberships()->create([
                'id' => (string) Str::ulid(),
                'installation_id' => $remoteInstallationId,
                'federation_link_id' => $link->id,
                'role' => CoalitionRole::Member,
                'status' => MembershipStatus::Active,
                'roster_revision' => $coalition->roster_revision,
                'joined_at' => now(),
            ]);
            $this->createCapability($identity, $coalition, $link, CapabilityDirection::Outbound);
            $this->createCapability(
                $remoteInstallationId,
                $coalition,
                $link,
                CapabilityDirection::Inbound,
                false,
            );
            $links[] = $link;
            $peers[$remoteInstallationId] = [
                'key' => $peerKey,
                'material' => $material,
            ];
        }

        return compact('actor', 'identity', 'operation', 'objective', 'coalition', 'links', 'peers');
    }

    private function createCapability(
        FederationIdentity|string $issuer,
        FederationCoalition $coalition,
        FederationLink $link,
        CapabilityDirection $direction,
        bool $isLocal = true,
    ): FederationCapability {
        $issuerId = $issuer instanceof FederationIdentity ? $issuer->id : $issuer;
        $peerId = $issuerId === $link->remote_installation_id
            ? FederationIdentity::query()->firstOrFail()->id
            : $link->remote_installation_id;
        $statement = [
            'peer_installation_id' => $peerId,
            'coalition_id' => $coalition->id,
            'resource_type' => FederationResourceType::WarPlanSnapshot->value,
            'direction' => $direction->value,
            'revision' => 1,
            'state' => CapabilityState::Active->value,
            'expires_at' => null,
        ];
        $statement['statement_hash'] = hash('sha256', CanonicalJson::encode($statement));

        return FederationCapability::query()->create([
            'id' => (string) Str::ulid(),
            'issuer_installation_id' => $issuerId,
            'peer_installation_id' => $peerId,
            'federation_coalition_id' => $coalition->id,
            'resource_type' => FederationResourceType::WarPlanSnapshot,
            'direction' => $direction,
            'revision' => 1,
            'state' => CapabilityState::Active,
            'is_local' => $isLocal,
            'statement_hash' => $statement['statement_hash'],
            'canonical_statement' => CanonicalJson::encode($statement),
        ]);
    }
}
