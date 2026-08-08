<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\NexusRuntime;
use App\Services\RuntimeCapabilities;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Telescope\Contracts\EntriesRepository;
use Laravel\Telescope\Telescope;
use Tests\TestCase;

class BootstrapTelemetryRedactionTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, callable> */
    private array $previousTelescopeFilters;

    public function createApplication(): Application
    {
        $telescopeEnabled = $this->environmentValue('TELESCOPE_ENABLED');
        $this->setEnvironmentValue('TELESCOPE_ENABLED', 'true');

        try {
            return parent::createApplication();
        } finally {
            $this->restoreEnvironmentValue('TELESCOPE_ENABLED', $telescopeEnabled);
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.url' => 'https://tenant.example.test',
            'nexus.runtime' => NexusRuntime::HostedTenant->value,
            'nexus.managed' => true,
            'nexus.tenant_id' => '01JZ0000000000000000000000',
            'nexus.release_id' => 'release-bootstrap-test',
        ]);
        $this->app->forgetInstance(RuntimeCapabilities::class);
        $this->app->forgetInstance(NexusRuntime::class);
        $this->previousTelescopeFilters = Telescope::$filterUsing;
        Telescope::$filterUsing = [static fn (): bool => true];
        Telescope::startRecording();
    }

    protected function tearDown(): void
    {
        Telescope::stopRecording();
        Telescope::$filterUsing = $this->previousTelescopeFilters;

        parent::tearDown();
    }

    public function test_failed_bootstrap_request_never_records_raw_credentials_or_smuggled_tokens(): void
    {
        $queryToken = 'nxb_'.str_repeat('a', 64);
        $headerToken = 'nxb_'.str_repeat('b', 64);
        $bodyToken = 'nxb_'.str_repeat('c', 64);
        $password = 'Bootstrap-local-password-938!';
        $cloudRecoveryCode = 'cloud-recovery-code-must-never-cross';

        $this
            ->withHeader('X-Nexus-Bootstrap-Token', $headerToken)
            ->postJson(
                '/api/internal/v1/bootstrap/redeem?bootstrap_token='.$queryToken,
                [
                    'bootstrap_token' => $bodyToken,
                    'name' => 'TenantOwner',
                    'email' => 'owner@example.test',
                    'password' => $password,
                    'password_confirmation' => $password,
                    'two_factor_recovery_code' => $cloudRecoveryCode,
                ],
            )
            ->assertUnprocessable();

        Telescope::store(app(EntriesRepository::class));

        $requestEntries = DB::table('telescope_entries')
            ->where('type', 'request')
            ->pluck('content');
        $this->assertNotEmpty($requestEntries);
        $recorded = $requestEntries->implode("\n");

        foreach ([$queryToken, $headerToken, $bodyToken, $password, $cloudRecoveryCode] as $secret) {
            $this->assertStringNotContainsString($secret, $recorded);
        }

        $this->assertStringNotContainsString('X-Nexus-Bootstrap-Token', $recorded);
        $this->assertStringContainsString('********', $recorded);
    }

    private function environmentValue(string $key): array
    {
        return [
            'env' => array_key_exists($key, $_ENV) ? $_ENV[$key] : null,
            'server' => array_key_exists($key, $_SERVER) ? $_SERVER[$key] : null,
            'getenv' => getenv($key) === false ? null : getenv($key),
        ];
    }

    /** @param array{env: mixed, server: mixed, getenv: mixed} $previous */
    private function restoreEnvironmentValue(string $key, array $previous): void
    {
        $this->restoreArrayEnvironmentValue($_ENV, $key, $previous['env']);
        $this->restoreArrayEnvironmentValue($_SERVER, $key, $previous['server']);

        if ($previous['getenv'] === null) {
            putenv($key);
        } else {
            putenv($key.'='.$previous['getenv']);
        }
    }

    private function setEnvironmentValue(string $key, string $value): void
    {
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
        putenv($key.'='.$value);
    }

    /** @param array<string, mixed> $environment */
    private function restoreArrayEnvironmentValue(array &$environment, string $key, mixed $value): void
    {
        if ($value === null) {
            unset($environment[$key]);

            return;
        }

        $environment[$key] = $value;
    }
}
