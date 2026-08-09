<?php

namespace Tests\Feature\Federation;

use App\Domain\Federation\Contracts\FederationTransport;
use App\Domain\Federation\DTO\FederationDiscoveryDocument;
use App\Domain\Federation\DTO\TransportResult;
use App\Domain\Federation\Enums\FederationMessageType;
use App\Domain\Federation\Enums\InboxStatus;
use App\Domain\Federation\Enums\OutboxStatus;
use App\Domain\Federation\Services\FederationIdentityService;
use App\Domain\Federation\Services\FederationInboxProcessor;
use App\Domain\Federation\Support\CanonicalJson;
use App\Domain\Federation\Transport\FederationEndpoint;
use App\Domain\Federation\Transport\PeerOrigin;
use App\Jobs\DeliverFederationEnvelopeJob;
use App\Models\FederationInboxMessage;
use App\Models\FederationOutboxMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class FederationDeliverySafetyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.url', 'https://nexus-one.example');
        config()->set('federation.enabled', true);
        config()->set('federation.features.inbound', true);
        config()->set('federation.features.linking', true);
        config()->set('federation.features.publishing', false);
        app(FederationIdentityService::class)->enable();
    }

    public function test_already_queued_publication_waits_while_the_publishing_kill_switch_is_disabled(): void
    {
        $message = FederationOutboxMessage::factory()->create([
            'message_type' => FederationMessageType::ResourcePublished,
            'resource_schema' => 'milcom.war-plan-snapshot/1.0',
        ]);
        $transport = $this->recordingTransport();
        $job = new DeliverFederationEnvelopeJob($message->id);

        $job->handle($transport);

        $this->assertSame(0, $transport->calls);
        $this->assertSame(OutboxStatus::Pending, $message->fresh()->status);

        config()->set('federation.features.publishing', true);
        $job->handle($transport);

        $this->assertSame(1, $transport->calls);
        $this->assertSame(OutboxStatus::TransportAccepted, $message->fresh()->status);
    }

    public function test_already_dispatched_control_message_waits_while_the_hard_gate_is_disabled(): void
    {
        $message = FederationOutboxMessage::factory()->create();
        $transport = $this->recordingTransport();
        config()->set('federation.enabled', false);

        (new DeliverFederationEnvelopeJob($message->id))->handle($transport);

        $this->assertSame(0, $transport->calls);
        $this->assertSame(OutboxStatus::Pending, $message->fresh()->status);
    }

    public function test_deterministic_poison_message_is_quarantined_after_ten_processing_attempts(): void
    {
        $payload = ['invitation_id' => (string) Str::ulid()];
        $message = FederationInboxMessage::factory()->create([
            'message_type' => FederationMessageType::LinkAcceptance,
            'decrypted_payload' => CanonicalJson::encode($payload),
            'processing_attempts' => 9,
        ]);

        app(FederationInboxProcessor::class)->process($message);

        $message = $message->fresh();
        $this->assertSame(10, $message->processing_attempts);
        $this->assertSame(InboxStatus::Quarantined, $message->status);
        $this->assertSame('temporary_unavailable', $message->safe_error_code);
        $this->assertNull($message->next_attempt_at);
        $this->assertNull($message->decrypted_payload);
        $this->assertNull($message->envelope_body);
    }

    private function recordingTransport(): FederationTransport
    {
        return new class implements FederationTransport
        {
            public int $calls = 0;

            public function discover(PeerOrigin $origin): FederationDiscoveryDocument
            {
                throw new \LogicException('Discovery is not used by the delivery job test.');
            }

            public function send(PeerOrigin $origin, FederationEndpoint $endpoint, string $body): TransportResult
            {
                $this->calls++;

                return new TransportResult(202, '{}', 'test-correlation');
            }
        };
    }
}
