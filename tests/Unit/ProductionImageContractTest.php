<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\NexusProcessRole;
use App\Services\RuntimeBuildMetadata;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class ProductionImageContractTest extends TestCase
{
    private string $projectRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->projectRoot = dirname(__DIR__, 2);
    }

    public function test_every_image_role_maps_to_one_fixed_command_without_a_shell(): void
    {
        $this->assertSame([
            NexusProcessRole::Web,
            NexusProcessRole::Queue,
            NexusProcessRole::Scheduler,
            NexusProcessRole::Migrator,
            NexusProcessRole::Bootstrap,
            NexusProcessRole::TenantEventConsumer,
        ], NexusProcessRole::cases());

        $commands = [];

        foreach (NexusProcessRole::cases() as $role) {
            $command = $role->command('/srv/nexus', '/usr/bin/php-test');
            $this->assertNotEmpty($command);
            $this->assertNotContains($command[0], ['sh', 'bash', '/bin/sh', '/bin/bash']);
            $this->assertNotContains('-c', $command);
            $this->assertNotContains('eval', $command);
            $commands[$role->value] = $command;
        }

        $this->assertSame(['/usr/local/bin/apache2-foreground'], $commands['web']);
        $this->assertSame([
            '/usr/bin/php-test',
            '/srv/nexus/artisan',
            'queue:work',
            '--queue=default,sync',
            '--sleep=3',
            '--tries=3',
            '--max-time=3600',
            '--no-interaction',
        ], $commands['queue']);
        $this->assertSame('WINCH', NexusProcessRole::Web->shutdownSignal());
        $this->assertSame('TERM', NexusProcessRole::Queue->shutdownSignal());
        $this->assertTrue(NexusProcessRole::TenantEventConsumer->isLongRunning());
        $this->assertFalse(NexusProcessRole::Migrator->isLongRunning());
        $this->assertFalse(NexusProcessRole::Bootstrap->isLongRunning());
    }

    public function test_dockerfile_uses_digest_pinned_builds_and_a_hardened_runtime_contract(): void
    {
        $dockerfile = $this->contents('Dockerfile');
        preg_match_all('/^FROM\s+([^\s]+)(?:\s+AS\s+\S+)?$/mi', $dockerfile, $matches);
        $this->assertNotEmpty($matches[1]);
        preg_match_all('/^FROM\s+\S+\s+AS\s+(\S+)$/mi', $dockerfile, $stageMatches);
        $knownStages = array_map(strtolower(...), $stageMatches[1]);

        foreach ($matches[1] as $image) {
            if (in_array(strtolower($image), $knownStages, true)) {
                continue;
            }

            $this->assertMatchesRegularExpression(
                '/\A[a-z0-9._\/-]+:[a-z0-9._-]+@sha256:[a-f0-9]{64}\z/D',
                $image,
            );
            $this->assertStringNotContainsString(':latest', $image);
        }

        $this->assertStringContainsString('USER www-data:www-data', $dockerfile);
        $this->assertStringContainsString('test "$(id -u www-data)" = 33', $dockerfile);
        $this->assertStringContainsString('ln -s ../storage/app/public public/storage', $dockerfile);
        $this->assertStringContainsString('EXPOSE 8080', $dockerfile);
        $this->assertStringContainsString('STOPSIGNAL SIGTERM', $dockerfile);
        $this->assertStringContainsString('HEALTHCHECK --interval=15s', $dockerfile);
        $this->assertStringContainsString(
            'ENTRYPOINT ["/usr/bin/tini", "--", "php", "/var/www/html/docker/runtime/entrypoint.php"]',
            $dockerfile,
        );
        $this->assertStringContainsString('CMD ["web"]', $dockerfile);
        $this->assertStringContainsString(
            'io.nexus.runtime.roles="web,queue,scheduler,migrator,bootstrap,event-consumer"',
            $dockerfile,
        );
        $this->assertStringContainsString(
            'io.nexus.runtime.sbom="/usr/share/nexus/sbom/nexus-ams.cdx.json"',
            $dockerfile,
        );
        $this->assertStringContainsString(
            'io.nexus.runtime.build-metadata="/usr/share/nexus/build.json"',
            $dockerfile,
        );
        $this->assertStringContainsString('io.nexus.runtime.stop-grace-period="960s"', $dockerfile);
        $this->assertStringContainsString('org.opencontainers.image.licenses="GPL-3.0-only"', $dockerfile);
        $this->assertStringContainsString(
            'org.opencontainers.image.base.name="docker.io/library/php:8.3-apache-bookworm"',
            $dockerfile,
        );
        $this->assertStringContainsString(
            'org.opencontainers.image.base.digest="sha256:0540815262141e96282c4734c7c3b8b87733fd97e98d9688a9eadcaeb2adcf88"',
            $dockerfile,
        );
        $this->assertStringContainsString(
            '0d5141f634bd1db6c1ddcda053d25ecf2c4fc1c395430d534fd3f8d51dd7f0b5',
            $dockerfile,
        );
        $this->assertStringContainsString('generate-build-metadata.php', $dockerfile);
        $this->assertStringContainsString('--base-image="docker.io/library/php:8.3-apache-bookworm@sha256:', $dockerfile);
        $this->assertStringNotContainsString('COPY . .', $dockerfile);
        $this->assertStringContainsString('COPY app ./app', $dockerfile);
        $this->assertStringContainsString('COPY bootstrap/app.php bootstrap/providers.php ./bootstrap/', $dockerfile);
        $this->assertStringContainsString('composer check-platform-reqs --no-dev', $dockerfile);
        $this->assertStringNotContainsString('COPY .env', $dockerfile);

        $apache = $this->contents('docker/runtime/apache-vhost.conf');
        $this->assertStringContainsString('AllowOverride None', $apache);
        $this->assertStringContainsString('RewriteRule ^ index.php [L]', $apache);
    }

    public function test_build_context_excludes_credentials_runtime_data_and_development_artifacts(): void
    {
        $ignore = $this->contents('.dockerignore');

        foreach ([
            '.git',
            '.env',
            '.env.*',
            '.npmrc',
            '.ssh',
            '.serena',
            'bootstrap/cache',
            'node_modules',
            'public/storage',
            'storage',
            'tests',
            'vendor',
            '**/auth.json',
            '**/*.log',
            '**/*.sqlite',
        ] as $entry) {
            $this->assertContains($entry, preg_split('/\R/', $ignore));
        }
    }

    public function test_entrypoint_rejects_unknown_or_conflicting_roles_before_starting_a_process(): void
    {
        $unknown = new Process(
            [PHP_BINARY, 'docker/runtime/entrypoint.php', 'shell'],
            $this->projectRoot,
        );
        $unknown->run();
        $this->assertSame(64, $unknown->getExitCode());
        $this->assertStringContainsString('not allowlisted', $unknown->getErrorOutput());

        $conflict = new Process(
            [PHP_BINARY, 'docker/runtime/entrypoint.php', 'queue'],
            $this->projectRoot,
            ['NEXUS_PROCESS_ROLE' => 'scheduler'],
        );
        $conflict->run();
        $this->assertSame(64, $conflict->getExitCode());
        $this->assertStringContainsString('do not match', $conflict->getErrorOutput());

        $disabledTenantEvents = new Process(
            [PHP_BINARY, 'docker/runtime/entrypoint.php', 'event-consumer'],
            $this->projectRoot,
        );
        $disabledTenantEvents->run();
        $this->assertSame(78, $disabledTenantEvents->getExitCode());
        $this->assertStringContainsString('not enabled', $disabledTenantEvents->getErrorOutput());

        $entrypoint = $this->contents('docker/runtime/entrypoint.php');
        $this->assertStringContainsString('proc_open(', $entrypoint);
        $this->assertStringNotContainsString('shell_exec(', $entrypoint);
        $this->assertStringNotContainsString('passthru(', $entrypoint);
        $this->assertStringNotContainsString('eval(', $entrypoint);
    }

    public function test_embedded_cyclonedx_sbom_is_deterministic_and_excludes_composer_dev_packages(): void
    {
        $temporaryDirectory = sys_get_temp_dir().'/nexus-sbom-'.bin2hex(random_bytes(8));
        $this->assertTrue(mkdir($temporaryDirectory, 0700));
        $dpkgStatus = $temporaryDirectory.'/dpkg-status';
        $firstOutput = $temporaryDirectory.'/first.cdx.json';
        $secondOutput = $temporaryDirectory.'/second.cdx.json';
        $differentReleaseOutput = $temporaryDirectory.'/different-release.cdx.json';
        file_put_contents($dpkgStatus, <<<'STATUS'
Package: libc6
Status: install ok installed
Architecture: amd64
Version: 2.36-9+deb12u13

Package: ignored-package
Status: deinstall ok config-files
Architecture: amd64
Version: 1.0.0
STATUS);

        try {
            $this->generateSbom($firstOutput, $dpkgStatus);
            $this->generateSbom($secondOutput, $dpkgStatus);
            $this->generateSbom($differentReleaseOutput, $dpkgStatus, str_repeat('c', 40));
            $first = $this->contents($firstOutput, absolute: true);
            $this->assertSame($first, $this->contents($secondOutput, absolute: true));
            $bom = json_decode($first, true, 512, JSON_THROW_ON_ERROR);
            $differentRelease = json_decode(
                $this->contents($differentReleaseOutput, absolute: true),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
            $this->assertSame('CycloneDX', $bom['bomFormat'] ?? null);
            $this->assertSame('1.6', $bom['specVersion'] ?? null);
            $this->assertNotSame($bom['serialNumber'] ?? null, $differentRelease['serialNumber'] ?? null);
            $this->assertSame(
                'GPL-3.0-only',
                $bom['metadata']['component']['licenses'][0]['license']['id'] ?? null,
            );
            $references = array_column($bom['components'] ?? [], 'bom-ref');
            $this->assertContains(
                'pkg:oci/php@sha256%3A'.str_repeat('d', 64).'?repository_url=docker.io%2Flibrary%2Fphp&tag=8.3-apache-bookworm',
                $references,
            );
            $this->assertContains('pkg:composer/laravel/framework@v13.23.0', $references);
            $this->assertContains('pkg:deb/debian/libc6@2.36-9%2Bdeb12u13?arch=amd64', $references);
            $this->assertNotContains('pkg:composer/phpunit/phpunit@12.5.33', $references);
            $this->assertTrue((bool) array_filter(
                $references,
                static fn (mixed $reference): bool => is_string($reference)
                    && str_starts_with($reference, 'pkg:generic/php-extension-'),
            ));
            $this->assertFalse((bool) array_filter(
                $references,
                static fn (mixed $reference): bool => is_string($reference)
                    && str_contains($reference, 'ignored-package'),
            ));
        } finally {
            @unlink($firstOutput);
            @unlink($secondOutput);
            @unlink($differentReleaseOutput);
            @unlink($dpkgStatus);
            @rmdir($temporaryDirectory);
        }
    }

    public function test_embedded_build_metadata_uses_runtime_contract_constants_and_allowlisted_roles(): void
    {
        $output = sys_get_temp_dir().'/nexus-build-metadata-'.bin2hex(random_bytes(8)).'.json';
        $process = new Process([
            PHP_BINARY,
            'docker/build/generate-build-metadata.php',
            '--output='.$output,
            '--version=2026.8.0-test',
            '--commit='.str_repeat('b', 40),
        ], $this->projectRoot);

        try {
            $process->mustRun();
            $metadata = json_decode(
                $this->contents($output, absolute: true),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
            $this->assertSame('2026.8.0-test', $metadata['application_version'] ?? null);
            $this->assertSame(str_repeat('b', 40), $metadata['commit'] ?? null);
            $this->assertSame(RuntimeBuildMetadata::RUNTIME_CONTRACT, $metadata['runtime_contract'] ?? null);
            $this->assertSame(RuntimeBuildMetadata::TENANT_SCHEMA, $metadata['tenant_schema'] ?? null);
            $this->assertSame(
                array_column(NexusProcessRole::cases(), 'value'),
                $metadata['roles'] ?? null,
            );
        } finally {
            @unlink($output);
        }
    }

    private function generateSbom(
        string $output,
        string $dpkgStatus,
        string $commit = '',
    ): void {
        $commit = $commit !== '' ? $commit : str_repeat('a', 40);
        $process = new Process([
            PHP_BINARY,
            'docker/build/generate-sbom.php',
            '--output='.$output,
            '--version=2026.8.0-test',
            '--commit='.$commit,
            '--base-image=docker.io/library/php:8.3-apache-bookworm@sha256:'.str_repeat('d', 64),
            '--dpkg-status='.$dpkgStatus,
        ], $this->projectRoot);
        $process->mustRun();
    }

    private function contents(string $path, bool $absolute = false): string
    {
        $contents = file_get_contents($absolute ? $path : $this->projectRoot.'/'.$path);
        $this->assertIsString($contents);

        return $contents;
    }
}
