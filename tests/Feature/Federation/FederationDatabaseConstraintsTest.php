<?php

namespace Tests\Feature\Federation;

use App\Models\FederationCapability;
use App\Models\FederationCoalition;
use App\Models\FederationCoalitionInvitation;
use App\Models\FederationCoalitionMembership;
use App\Models\FederationCoalitionProposal;
use App\Models\FederationIdentity;
use App\Models\FederationIdentityKey;
use App\Models\FederationInboxMessage;
use App\Models\FederationLink;
use App\Models\FederationLinkInvitation;
use App\Models\FederationOutboxMessage;
use App\Models\FederationPeerKey;
use App\Models\FederationPublication;
use App\Models\FederationPublicationDelivery;
use App\Models\FederationPublicationVersion;
use App\Models\FederationReceivedResource;
use App\Models\FederationReceivedVersion;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class FederationDatabaseConstraintsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        foreach (glob(dirname(__DIR__, 3).'/database/factories/Federation*Factory.php') ?: [] as $factoryFile) {
            require_once $factoryFile;
        }

        parent::setUp();
    }

    public function test_all_federation_factories_create_safe_default_rows(): void
    {
        $identity = FederationIdentity::factory()->create();
        $key = FederationIdentityKey::factory()->for($identity, 'identity')->create();
        $link = FederationLink::factory()->create();
        $peerKey = FederationPeerKey::factory()->for($link, 'link')->create();
        $linkInvitation = FederationLinkInvitation::factory()->for($link, 'link')->create();
        $coalition = FederationCoalition::factory()->create();
        $membership = FederationCoalitionMembership::factory()->for($coalition, 'coalition')->create();
        $coalitionInvitation = FederationCoalitionInvitation::factory()
            ->for($coalition, 'coalition')
            ->for($link, 'link')
            ->state(['installation_id' => $link->remote_installation_id])
            ->create();
        $proposal = FederationCoalitionProposal::factory()->for($coalition, 'coalition')->create();
        $capability = FederationCapability::factory()->for($coalition, 'coalition')->create();
        $publication = FederationPublication::factory()->create();
        $version = FederationPublicationVersion::factory()->for($publication, 'publication')->create();
        $delivery = FederationPublicationDelivery::factory()
            ->for($version, 'version')
            ->for($link, 'link')
            ->state(['recipient_installation_id' => $link->remote_installation_id])
            ->create();
        $receivedResource = FederationReceivedResource::factory()->create();
        $receivedVersion = FederationReceivedVersion::factory()
            ->for($receivedResource, 'resource')
            ->create();
        $inbox = FederationInboxMessage::factory()->create();
        $outbox = FederationOutboxMessage::factory()->for($link, 'link')->create();

        foreach ([
            $identity,
            $key,
            $link,
            $peerKey,
            $linkInvitation,
            $coalition,
            $membership,
            $coalitionInvitation,
            $proposal,
            $capability,
            $publication,
            $version,
            $delivery,
            $receivedResource,
            $receivedVersion,
            $inbox,
            $outbox,
        ] as $model) {
            $this->assertModelExists($model);
        }

        $this->assertSame('test-signing-public-key', $linkInvitation->discovery_snapshot['current_key']['signing_public_key']);
        $this->assertSame('1.0', $outbox->protocol_version);
        $this->assertSame(strlen($delivery->canonical_payload), $delivery->payload_bytes);
    }

    public function test_identity_and_key_singleton_constraints_are_enforced(): void
    {
        $identity = FederationIdentity::factory()->create();
        FederationIdentityKey::factory()->for($identity, 'identity')->active()->create();

        $this->assertConstraintViolation(function (): void {
            FederationIdentity::factory()->create();
        });
        $this->assertConstraintViolation(function () use ($identity): void {
            FederationIdentityKey::factory()
                ->for($identity, 'identity')
                ->active()
                ->state(['generation' => 2])
                ->create();
        });
    }

    public function test_link_and_pending_link_invitation_constraints_are_enforced(): void
    {
        $remoteInstallationId = (string) Str::ulid();
        FederationLink::factory()->state([
            'remote_installation_id' => $remoteInstallationId,
        ])->create();

        $this->assertConstraintViolation(function () use ($remoteInstallationId): void {
            FederationLink::factory()->state([
                'remote_installation_id' => $remoteInstallationId,
            ])->create();
        });

        $origin = 'https://pending-peer.example.test';
        FederationLinkInvitation::factory()->state([
            'peer_origin' => $origin,
            'pending_key' => 1,
        ])->create();
        $this->assertConstraintViolation(function () use ($origin): void {
            FederationLinkInvitation::factory()->state([
                'peer_origin' => $origin,
                'pending_key' => 1,
            ])->create();
        });
    }

    public function test_coalition_membership_invitation_and_proposal_pending_keys_are_enforced(): void
    {
        $coalition = FederationCoalition::factory()->create();
        $installationId = (string) Str::ulid();
        FederationCoalitionMembership::factory()
            ->for($coalition, 'coalition')
            ->state(['installation_id' => $installationId])
            ->create();

        $this->assertConstraintViolation(function () use ($coalition, $installationId): void {
            FederationCoalitionMembership::factory()
                ->for($coalition, 'coalition')
                ->state(['installation_id' => $installationId])
                ->create();
        });

        $link = FederationLink::factory()->create();
        FederationCoalitionInvitation::factory()
            ->for($coalition, 'coalition')
            ->for($link, 'link')
            ->state([
                'installation_id' => $installationId,
                'pending_key' => 1,
            ])
            ->create();
        $this->assertConstraintViolation(function () use ($coalition, $link, $installationId): void {
            FederationCoalitionInvitation::factory()
                ->for($coalition, 'coalition')
                ->for($link, 'link')
                ->state([
                    'installation_id' => $installationId,
                    'pending_key' => 1,
                ])
                ->create();
        });

        $workflowKey = 'same-workflow';
        FederationCoalitionProposal::factory()
            ->for($coalition, 'coalition')
            ->state([
                'workflow_key' => $workflowKey,
                'pending_key' => 1,
            ])
            ->create();
        $this->assertConstraintViolation(function () use ($coalition, $workflowKey): void {
            FederationCoalitionProposal::factory()
                ->for($coalition, 'coalition')
                ->state([
                    'workflow_key' => $workflowKey,
                    'pending_key' => 1,
                ])
                ->create();
        });
    }

    public function test_capability_revision_is_unique_per_directional_peer_statement(): void
    {
        $coalition = FederationCoalition::factory()->create();
        $issuer = (string) Str::ulid();
        $peer = (string) Str::ulid();
        $attributes = [
            'issuer_installation_id' => $issuer,
            'peer_installation_id' => $peer,
            'revision' => 7,
        ];
        FederationCapability::factory()->for($coalition, 'coalition')->state($attributes)->create();

        $this->assertConstraintViolation(function () use ($coalition, $attributes): void {
            FederationCapability::factory()->for($coalition, 'coalition')->state($attributes)->create();
        });
    }

    public function test_publication_versions_and_deliveries_are_unique_per_recipient(): void
    {
        $publication = FederationPublication::factory()->create();
        $version = FederationPublicationVersion::factory()
            ->for($publication, 'publication')
            ->state(['version' => 4, 'revision' => 9])
            ->create();

        $this->assertConstraintViolation(function () use ($publication): void {
            FederationPublicationVersion::factory()
                ->for($publication, 'publication')
                ->state(['version' => 4, 'revision' => 9])
                ->create();
        });

        $link = FederationLink::factory()->active()->create();
        $deliveryAttributes = [
            'recipient_installation_id' => $link->remote_installation_id,
        ];
        FederationPublicationDelivery::factory()
            ->for($version, 'version')
            ->for($link, 'link')
            ->state($deliveryAttributes)
            ->create();

        $this->assertConstraintViolation(function () use ($version, $link, $deliveryAttributes): void {
            FederationPublicationDelivery::factory()
                ->for($version, 'version')
                ->for($link, 'link')
                ->state($deliveryAttributes)
                ->create();
        });
    }

    public function test_received_resource_and_version_provenance_is_unique(): void
    {
        $sourceInstallationId = (string) Str::ulid();
        $sourcePublicationId = (string) Str::ulid();
        FederationReceivedResource::factory()->state([
            'source_installation_id' => $sourceInstallationId,
            'source_publication_id' => $sourcePublicationId,
        ])->create();

        $this->assertConstraintViolation(function () use ($sourceInstallationId, $sourcePublicationId): void {
            FederationReceivedResource::factory()->state([
                'source_installation_id' => $sourceInstallationId,
                'source_publication_id' => $sourcePublicationId,
            ])->create();
        });

        $resource = FederationReceivedResource::factory()->create();
        $sourceVersionId = (string) Str::ulid();
        $versionAttributes = [
            'source_installation_id' => $resource->source_installation_id,
            'source_publication_id' => $resource->source_publication_id,
            'source_version_id' => $sourceVersionId,
            'version' => 3,
        ];
        FederationReceivedVersion::factory()->for($resource, 'resource')->state($versionAttributes)->create();

        $this->assertConstraintViolation(function () use ($resource, $versionAttributes): void {
            FederationReceivedVersion::factory()
                ->for($resource, 'resource')
                ->state($versionAttributes)
                ->create();
        });
    }

    public function test_inbox_and_outbox_replay_keys_are_unique(): void
    {
        $inbox = FederationInboxMessage::factory()->create();
        $this->assertConstraintViolation(function () use ($inbox): void {
            FederationInboxMessage::factory()->state([
                'sender_installation_id' => $inbox->sender_installation_id,
                'message_id' => $inbox->message_id,
            ])->create();
        });

        $this->assertConstraintViolation(function () use ($inbox): void {
            FederationInboxMessage::factory()->state([
                'sender_installation_id' => $inbox->sender_installation_id,
                'sender_key_id' => $inbox->sender_key_id,
                'nonce' => $inbox->nonce,
                'message_id' => (string) Str::ulid(),
            ])->create();
        });

        $outbox = FederationOutboxMessage::factory()->create();
        $this->assertConstraintViolation(function () use ($outbox): void {
            FederationOutboxMessage::factory()->state([
                'sender_installation_id' => $outbox->sender_installation_id,
                'message_id' => $outbox->message_id,
            ])->create();
        });

        $this->assertConstraintViolation(function () use ($outbox): void {
            FederationOutboxMessage::factory()->state([
                'sender_installation_id' => $outbox->sender_installation_id,
                'sender_key_id' => $outbox->sender_key_id,
                'nonce' => $outbox->nonce,
                'message_id' => (string) Str::ulid(),
            ])->create();
        });
    }

    public function test_mysql_constraint_metadata_is_skipped_without_an_isolated_mysql_test_connection(): void
    {
        if (! $this->hasIsolatedMysqlConnection()) {
            $this->markTestSkipped('MySQL federation assertions require the isolated MySQL test connection.');
        }

        $indexes = Schema::connection('mysql')->getIndexes('federation_inbox_messages');
        $indexNames = array_column($indexes, 'name');

        $this->assertContains('fed_inbox_sender_message_unique', $indexNames);
        $this->assertContains('fed_inbox_sender_nonce_unique', $indexNames);
    }

    private function assertConstraintViolation(Closure $operation): void
    {
        try {
            $operation();
            $this->fail('The expected federation database constraint was not enforced.');
        } catch (QueryException $exception) {
            $this->assertSame('23000', (string) $exception->getCode());
        }
    }

    private function hasIsolatedMysqlConnection(): bool
    {
        $database = (string) config('database.connections.mysql.database');

        return config('database.default') === 'mysql'
            && $database !== ''
            && str_contains(strtolower($database), 'test');
    }
}
