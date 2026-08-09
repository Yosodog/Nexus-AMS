<?php

namespace Tests\Feature\Federation;

use App\Domain\Federation\Contracts\FederationTransport;
use App\Domain\Federation\DTO\FederationDiscoveryDocument;
use App\Domain\Federation\DTO\TransportResult;
use App\Domain\Federation\Enums\FederationLinkStatus;
use App\Domain\Federation\Enums\FederationMessageType;
use App\Domain\Federation\Services\FederationIdentityService;
use App\Domain\Federation\Services\FederationInboxProcessor;
use App\Domain\Federation\Services\FederationLinkService;
use App\Domain\Federation\Transport\FederationEndpoint;
use App\Domain\Federation\Transport\PeerOrigin;
use App\Models\FederationInboxMessage;
use App\Models\FederationLink;
use App\Models\FederationLinkInvitation;
use App\Models\FederationOutboxMessage;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class FederationTwoInstallationHandshakeTest extends TestCase
{
    use RefreshDatabase;

    public function test_two_isolated_installations_complete_the_real_http_handshake(): void
    {
        $sourceApp = $this->app;
        $peerApp = null;

        try {
            $this->configureInstallation($sourceApp, 'https://nexus-a.example');
            $this->activateApplication($sourceApp);
            Queue::fake();
            Http::preventStrayRequests();
            $sourceIdentity = app(FederationIdentityService::class)->enable();
            $sourceActorId = User::factory()->create()->id;
            $sourceAppKey = (string) config('app.key');

            $peerApp = $this->bootIsolatedPeerApplication();
            $this->activateApplication($peerApp);
            Queue::fake();
            Http::preventStrayRequests();
            $peerIdentity = app(FederationIdentityService::class)->enable();
            $peerActorId = User::factory()->create()->id;
            $peerAppKey = (string) config('app.key');
            $discoveryResponse = $this->request($peerApp, 'GET', 'https://nexus-b.example/.well-known/nexus-federation');
            $this->assertSame(200, $discoveryResponse->getStatusCode());
            $peerDiscovery = FederationDiscoveryDocument::fromJson((string) $discoveryResponse->getContent());

            $this->assertNotSame($sourceAppKey, $peerAppKey);
            $this->assertNotSame($sourceIdentity->id, $peerIdentity->id);

            $this->activateApplication($sourceApp);
            Queue::fake();
            $sourceApp->instance(FederationTransport::class, new class($peerDiscovery) implements FederationTransport
            {
                public function __construct(private readonly FederationDiscoveryDocument $discovery) {}

                public function discover(PeerOrigin $origin): FederationDiscoveryDocument
                {
                    return $this->discovery;
                }

                public function send(PeerOrigin $origin, FederationEndpoint $endpoint, string $body): TransportResult
                {
                    throw new \LogicException('The harness transfers durable envelopes through the peer HTTP kernel.');
                }
            });
            $sourceLink = app(FederationLinkService::class)->begin(
                $peerDiscovery->origin,
                $sourceActorId,
                true,
            );
            $sourceInvitationId = $sourceLink->invitations()->firstOrFail()->id;
            $requestEnvelope = $this->outboxEnvelope(FederationMessageType::LinkRequest);

            $this->activateApplication($peerApp);
            $response = $this->request(
                $peerApp,
                'POST',
                'https://nexus-b.example/api/v1/federation/handshakes',
                $requestEnvelope,
            );
            $this->assertSame(202, $response->getStatusCode());
            $this->processLatestInbox(FederationMessageType::LinkRequest);
            $this->assertNull($this->latestInbox(FederationMessageType::LinkRequest)->decrypted_payload);
            $peerInvitation = FederationLinkInvitation::query()->where('direction', 'inbound')->firstOrFail();
            app(FederationLinkService::class)->approveIncoming($peerInvitation, $peerActorId);
            $acceptanceEnvelope = $this->outboxEnvelope(FederationMessageType::LinkAcceptance);

            $this->activateApplication($sourceApp);
            $response = $this->request(
                $sourceApp,
                'POST',
                'https://nexus-a.example/api/v1/federation/handshakes',
                $acceptanceEnvelope,
            );
            $this->assertSame(202, $response->getStatusCode());
            $this->processLatestInbox(FederationMessageType::LinkAcceptance);
            $this->assertNull($this->latestInbox(FederationMessageType::LinkAcceptance)->decrypted_payload);
            app(FederationLinkService::class)->finalizeOutgoing(
                FederationLinkInvitation::query()->findOrFail($sourceInvitationId),
                $sourceActorId,
            );
            $activationEnvelope = $this->outboxEnvelope(FederationMessageType::LinkActivation);

            $this->activateApplication($peerApp);
            $response = $this->request(
                $peerApp,
                'POST',
                'https://nexus-b.example/api/v1/federation/handshakes',
                $activationEnvelope,
            );
            $this->assertSame(202, $response->getStatusCode());
            $this->processLatestInbox(FederationMessageType::LinkActivation);
            $this->assertNull($this->latestInbox(FederationMessageType::LinkActivation)->decrypted_payload);
            $acknowledgmentEnvelope = $this->outboxEnvelope(FederationMessageType::LinkActivation);
            $this->assertSame(FederationLinkStatus::Active, FederationLink::query()->firstOrFail()->status);

            $this->activateApplication($sourceApp);
            $response = $this->request(
                $sourceApp,
                'POST',
                'https://nexus-a.example/api/v1/federation/handshakes',
                $acknowledgmentEnvelope,
            );
            $this->assertSame(202, $response->getStatusCode());
            $this->processLatestInbox(FederationMessageType::LinkActivation);
            $this->assertNull($this->latestInbox(FederationMessageType::LinkActivation)->decrypted_payload);
            $this->assertSame(FederationLinkStatus::Active, FederationLink::query()->firstOrFail()->status);

            $this->activateApplication($peerApp);
            $this->assertSame(FederationLinkStatus::Active, FederationLink::query()->firstOrFail()->status);
        } finally {
            $this->activateApplication($sourceApp);

            if ($peerApp instanceof Application) {
                $peerApp->make('db')->disconnect();
            }
        }
    }

    private function bootIsolatedPeerApplication(): Application
    {
        /** @var Application $application */
        $application = require dirname(__DIR__, 3).'/bootstrap/app.php';
        $application->make(ConsoleKernel::class)->bootstrap();
        $this->configureInstallation($application, 'https://nexus-b.example');
        $application->make('config')->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $application->make('config')->set('database.default', 'federation_peer');
        $application->make('config')->set('database.connections.federation_peer', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        $application->make('db')->purge('federation_peer');

        /** @var Migrator $migrator */
        $migrator = $application->make('migrator');
        $migrator->setConnection('federation_peer');

        if (! $migrator->getRepository()->repositoryExists()) {
            $migrator->getRepository()->createRepository();
        }

        $migrator->run([$application->databasePath('migrations')]);

        return $application;
    }

    private function configureInstallation(Application $application, string $origin): void
    {
        $config = $application->make('config');
        $config->set('app.url', $origin);
        $config->set('cache.default', 'array');
        $config->set('queue.default', 'sync');
        $config->set('federation.enabled', true);
        $config->set('federation.features.inbound', true);
        $config->set('federation.features.linking', true);
        $config->set('federation.features.publishing', false);
    }

    private function activateApplication(Application $application): void
    {
        Application::setInstance($application);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($application);
        Model::setConnectionResolver($application->make('db'));
        Model::setEventDispatcher($application->make('events'));
    }

    private function request(
        Application $application,
        string $method,
        string $url,
        string $content = '',
    ): Response {
        $request = Request::create(
            $url,
            $method,
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
            ],
            content: $content,
        );
        $kernel = $application->make(HttpKernel::class);
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);

        return $response;
    }

    private function outboxEnvelope(FederationMessageType $type): string
    {
        return (string) FederationOutboxMessage::query()
            ->where('message_type', $type->value)
            ->latest('created_at')
            ->firstOrFail()
            ->envelope_body;
    }

    private function processLatestInbox(FederationMessageType $type): void
    {
        app(FederationInboxProcessor::class)->process($this->latestInbox($type));
    }

    private function latestInbox(FederationMessageType $type): FederationInboxMessage
    {
        return FederationInboxMessage::query()
            ->where('message_type', $type->value)
            ->latest('created_at')
            ->firstOrFail();
    }
}
