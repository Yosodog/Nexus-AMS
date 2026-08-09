<?php

namespace Tests\Feature\Federation;

use App\Domain\Federation\Enums\CoalitionRole;
use App\Domain\Federation\Enums\FederationLinkStatus;
use App\Domain\Federation\Enums\FederationResourceType;
use App\Domain\Federation\Enums\FederationWorkflowStatus;
use App\Domain\Federation\Enums\ImportState;
use App\Domain\Federation\Enums\MembershipStatus;
use App\Domain\Federation\Enums\ReceivedDisposition;
use App\Domain\Federation\Enums\ReceivedResourceState;
use App\Models\FederationCoalition;
use App\Models\FederationCoalitionInvitation;
use App\Models\FederationCoalitionProposal;
use App\Models\FederationLink;
use App\Models\FederationLinkInvitation;
use App\Models\FederationReceivedResource;
use App\Models\FederationReceivedVersion;
use App\Services\StaffWorkQueue\Sources\FederationWorkQueueSource;
use App\Services\StaffWorkQueue\StaffWorkQueueRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\Milcom\Concerns\BuildsMilcomFixtures;
use Tests\TestCase;

class FederationWorkQueueTest extends TestCase
{
    use BuildsMilcomFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.url', 'https://nexus-one.example');
        config()->set('federation.enabled', true);
    }

    public function test_all_federation_workflow_categories_emit_safe_items(): void
    {
        $link = $this->createLink();
        $coalition = $this->createCoalition($link);

        FederationLinkInvitation::query()->create([
            'id' => (string) Str::ulid(),
            'federation_link_id' => $link->id,
            'direction' => 'inbound',
            'peer_origin' => 'https://peer.example',
            'peer_installation_id' => $link->remote_installation_id,
            'token_hash' => hash('sha256', 'link-invitation'),
            'status' => FederationWorkflowStatus::Pending,
            'pending_key' => 1,
            'expires_at' => now()->addDay(),
        ]);

        FederationCoalitionInvitation::query()->create([
            'id' => (string) Str::ulid(),
            'federation_coalition_id' => $coalition->id,
            'federation_link_id' => $link->id,
            'installation_id' => $link->remote_installation_id,
            'role' => CoalitionRole::Member,
            'direction' => 'inbound',
            'token_hash' => hash('sha256', 'coalition-invitation'),
            'status' => FederationWorkflowStatus::Pending,
            'pending_key' => 1,
            'expires_at' => now()->addDay(),
        ]);

        FederationCoalitionProposal::query()->create([
            'id' => (string) Str::ulid(),
            'federation_coalition_id' => $coalition->id,
            'proposer_installation_id' => $link->remote_installation_id,
            'proposal_type' => 'member.role',
            'workflow_key' => 'role:'.$link->remote_installation_id,
            'target_installation_id' => $link->remote_installation_id,
            'requested_role' => CoalitionRole::Admin,
            'base_roster_revision' => 1,
            'payload_hash' => hash('sha256', 'private-proposal-payload'),
            'canonical_payload' => json_encode([
                'title' => 'Private coalition proposal title',
                'instructions' => 'Private coalition instructions',
                'ciphertext' => 'private-ciphertext',
            ], JSON_THROW_ON_ERROR),
            'status' => FederationWorkflowStatus::Pending,
            'pending_key' => 1,
            'expires_at' => now()->addDay(),
        ]);

        $resource = FederationReceivedResource::query()->create([
            'id' => (string) Str::ulid(),
            'federation_link_id' => $link->id,
            'source_installation_id' => $link->remote_installation_id,
            'source_publication_id' => (string) Str::ulid(),
            'coalition_id' => $coalition->id,
            'resource_type' => FederationResourceType::WarPlanSnapshot,
            'state' => ReceivedResourceState::PendingReview,
            'current_version' => 2,
            'current_revision' => 2,
            'expires_at' => now()->addDay(),
        ]);

        $pendingVersion = FederationReceivedVersion::query()->create([
            'id' => (string) Str::ulid(),
            'federation_received_resource_id' => $resource->id,
            'source_installation_id' => $link->remote_installation_id,
            'source_publication_id' => $resource->source_publication_id,
            'source_version_id' => (string) Str::ulid(),
            'version' => 1,
            'revision' => 1,
            'source_generation' => 1,
            'roster_revision' => 1,
            'schema_version' => '1.0',
            'canonical_payload' => json_encode([
                'title' => 'Private war plan title',
                'recipient_instructions' => 'Private instructions',
                'key' => 'private-key',
            ], JSON_THROW_ON_ERROR),
            'payload_hash' => hash('sha256', 'private-war-plan-payload'),
            'payload_bytes' => 128,
            'disposition' => ReceivedDisposition::Pending,
            'import_state' => ImportState::NotRequested,
            'expires_at' => now()->addDay(),
        ]);

        $blockedVersion = $pendingVersion->replicate(['id', 'created_at', 'updated_at']);
        $blockedVersion->id = (string) Str::ulid();
        $blockedVersion->version = 2;
        $blockedVersion->revision = 2;
        $blockedVersion->source_version_id = (string) Str::ulid();
        $blockedVersion->disposition = ReceivedDisposition::Accepted;
        $blockedVersion->import_state = ImportState::BlockedMissingTargets;
        $blockedVersion->save();

        $heldOperation = $this->createMilcomOperation([
            'federation_action_required' => true,
            'federation_held_at' => now()->subHour(),
        ]);

        $categories = [
            FederationWorkQueueSource::LINK_APPROVALS => 1,
            FederationWorkQueueSource::COALITION_WORKFLOWS => 2,
            FederationWorkQueueSource::RECEIVED_REVIEWS => 1,
            FederationWorkQueueSource::BLOCKED_IMPORTS => 1,
            FederationWorkQueueSource::HELD_OPERATIONS => 1,
        ];

        foreach ($categories as $category => $expectedCount) {
            $source = new FederationWorkQueueSource($category);
            $items = $source->load();

            $this->assertCount($expectedCount, $items, $category);
            $serialized = json_encode(array_map(
                static fn ($item): array => $item->toArray(),
                $items,
            ), JSON_THROW_ON_ERROR);

            $this->assertStringNotContainsString('Private war plan title', $serialized);
            $this->assertStringNotContainsString('Private coalition proposal title', $serialized);
            $this->assertStringNotContainsString('Private instructions', $serialized);
            $this->assertStringNotContainsString('private-key', $serialized);
            $this->assertStringNotContainsString('private-ciphertext', $serialized);
        }

        $heldItem = (new FederationWorkQueueSource(FederationWorkQueueSource::HELD_OPERATIONS))->load()[0];
        $this->assertSame($heldOperation->id, $heldItem->id);
        $this->assertStringContainsString('#received', $heldItem->url);
    }

    public function test_registry_filters_federation_categories_by_their_item_permission(): void
    {
        $manageFederation = $this->grantPermissions(
            $this->createVerifiedAdmin(['nation_id' => 940101]),
            ['manage-federation'],
        );
        $manageCoalitions = $this->grantPermissions(
            $this->createVerifiedAdmin(['nation_id' => 940102]),
            ['manage-coalitions'],
        );
        $reviewPlans = $this->grantPermissions(
            $this->createVerifiedAdmin(['nation_id' => 940103]),
            ['review-federated-war-plans'],
        );
        $importPlans = $this->grantPermissions(
            $this->createVerifiedAdmin(['nation_id' => 940104]),
            ['import-federated-war-plans'],
        );

        $registry = app(StaffWorkQueueRegistry::class);

        $this->assertSame(
            [FederationWorkQueueSource::LINK_APPROVALS],
            array_keys($registry->allowedTypes($manageFederation)),
        );
        $this->assertSame(
            [FederationWorkQueueSource::COALITION_WORKFLOWS],
            array_keys($registry->allowedTypes($manageCoalitions)),
        );
        $this->assertSame(
            [FederationWorkQueueSource::RECEIVED_REVIEWS],
            array_keys($registry->allowedTypes($reviewPlans)),
        );
        $this->assertSame(
            [FederationWorkQueueSource::BLOCKED_IMPORTS, FederationWorkQueueSource::HELD_OPERATIONS],
            array_keys($registry->allowedTypes($importPlans)),
        );
    }

    private function createLink(): FederationLink
    {
        return FederationLink::query()->create([
            'id' => (string) Str::ulid(),
            'remote_installation_id' => (string) Str::ulid(),
            'remote_display_name' => 'Peer Nexus',
            'approved_origin' => 'https://peer.example',
            'status' => FederationLinkStatus::Active,
            'remote_ownership_epoch' => 1,
            'negotiated_protocol_version' => '1.0',
            'active_at' => now(),
        ]);
    }

    private function createCoalition(FederationLink $link): FederationCoalition
    {
        $coalition = FederationCoalition::query()->create([
            'id' => (string) Str::ulid(),
            'name' => 'Operations',
            'coordinator_installation_id' => (string) Str::ulid(),
            'status' => 'active',
            'roster_revision' => 1,
            'roster_hash' => str_repeat('a', 64),
            'canonical_manifest' => '{}',
        ]);
        $coalition->memberships()->create([
            'id' => (string) Str::ulid(),
            'installation_id' => $link->remote_installation_id,
            'federation_link_id' => $link->id,
            'role' => CoalitionRole::Member,
            'status' => MembershipStatus::Active,
            'roster_revision' => 1,
            'joined_at' => now(),
        ]);

        return $coalition;
    }
}
