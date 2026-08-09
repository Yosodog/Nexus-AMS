<?php

declare(strict_types=1);

namespace Tests\Integration;

use Illuminate\Support\Str;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class ProductionImageTest extends TestCase
{
    private string $projectRoot;

    private string $imageTag;

    /** @var list<string> */
    private array $containers = [];

    private ?string $environmentFile = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('NEXUS_PRODUCTION_IMAGE_TEST') !== '1') {
            $this->markTestSkipped('Set NEXUS_PRODUCTION_IMAGE_TEST=1 to build the production image.');
        }

        $this->projectRoot = dirname(__DIR__, 2);
        $suffix = strtolower(substr((string) Str::ulid(), -12));
        $this->imageTag = 'nexus-ams-production-test:'.$suffix;

        $this->runProcess(['docker', 'info'], timeout: 30);
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->containers) as $container) {
            $this->runProcessIgnoringFailure(['docker', 'rm', '--force', $container]);
        }

        if (isset($this->imageTag)) {
            $this->runProcessIgnoringFailure(['docker', 'image', 'rm', '--force', $this->imageTag]);
        }

        if ($this->environmentFile !== null && is_file($this->environmentFile)) {
            @unlink($this->environmentFile);
        }

        parent::tearDown();
    }

    public function test_one_non_root_image_supports_allowlisted_roles_health_and_graceful_web_shutdown(): void
    {
        $commit = trim($this->runProcess(['git', 'rev-parse', 'HEAD'])->getOutput());
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{40}\z/D', $commit);
        $this->runProcess([
            'docker',
            'build',
            '--pull',
            '--file',
            'Dockerfile',
            '--tag',
            $this->imageTag,
            '--build-arg',
            'NEXUS_APPLICATION_VERSION=2026.8.0-test',
            '--build-arg',
            'NEXUS_COMMIT_SHA='.$commit,
            '.',
        ], timeout: 1_800);

        $configuration = json_decode(trim($this->runProcess([
            'docker',
            'image',
            'inspect',
            $this->imageTag,
            '--format',
            '{{json .Config}}',
        ])->getOutput()), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('www-data:www-data', $configuration['User'] ?? null);
        $this->assertSame(
            ['/usr/bin/tini', '--', 'php', '/var/www/html/docker/runtime/entrypoint.php'],
            $configuration['Entrypoint'] ?? null,
        );
        $this->assertSame(['web'], $configuration['Cmd'] ?? null);
        $this->assertSame('SIGTERM', $configuration['StopSignal'] ?? null);

        $runtime = json_decode(trim($this->runProcess([
            'docker',
            'run',
            '--rm',
            '--entrypoint',
            'php',
            $this->imageTag,
            '-r',
            <<<'PHP'
$build = json_decode(file_get_contents('/usr/share/nexus/build.json'), true, 512, JSON_THROW_ON_ERROR);
$sbom = json_decode(file_get_contents('/usr/share/nexus/sbom/nexus-ams.cdx.json'), true, 512, JSON_THROW_ON_ERROR);
$sbomReferences = array_column($sbom['components'] ?? [], 'bom-ref');
echo json_encode([
    'php' => PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION,
    'extensions' => array_values(array_filter(
        ['bcmath', 'gd', 'intl', 'pcntl', 'pdo_mysql', 'posix', 'redis', 'zip'],
        static fn (string $extension): bool => extension_loaded($extension),
    )),
    'assets' => is_file('/var/www/html/public/build/manifest.json'),
    'build' => $build,
    'vendor' => is_file('/var/www/html/vendor/autoload.php'),
    'sbom' => $sbom['bomFormat'] ?? null,
    'base_image' => (bool) array_filter(
        $sbomReferences,
        static fn (mixed $reference): bool => is_string($reference)
            && str_starts_with($reference, 'pkg:oci/php@sha256%3A'),
    ),
    'runtime_context_clean' => ! is_file('/var/www/html/storage/.DS_Store')
        && ! is_file('/var/www/html/storage/app/public/.gitignore')
        && ! is_file('/var/www/html/bootstrap/cache/.gitignore'),
]);
PHP,
        ])->getOutput()), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('8.3', $runtime['php'] ?? null);
        $this->assertSame(
            ['bcmath', 'gd', 'intl', 'pcntl', 'pdo_mysql', 'posix', 'redis', 'zip'],
            $runtime['extensions'] ?? null,
        );
        $this->assertTrue($runtime['assets'] ?? false);
        $this->assertSame('2026.8.0-test', $runtime['build']['application_version'] ?? null);
        $this->assertSame($commit, $runtime['build']['commit'] ?? null);
        $this->assertSame(42, $runtime['build']['tenant_schema'] ?? null);
        $this->assertTrue($runtime['vendor'] ?? false);
        $this->assertSame('CycloneDX', $runtime['sbom'] ?? null);
        $this->assertTrue($runtime['base_image'] ?? false);
        $this->assertTrue($runtime['runtime_context_clean'] ?? false);

        $invalidRole = $this->runProcessIgnoringFailure([
            'docker',
            'run',
            '--rm',
            $this->imageTag,
            '/bin/sh',
        ]);
        $this->assertSame(64, $invalidRole->getExitCode());
        $this->assertStringContainsString('not allowlisted', $invalidRole->getErrorOutput());

        $this->environmentFile = tempnam(sys_get_temp_dir(), 'nexus-image-env-') ?: null;
        $this->assertNotNull($this->environmentFile);
        $this->assertTrue(chmod($this->environmentFile, 0600));
        $this->assertNotFalse(file_put_contents($this->environmentFile, implode("\n", [
            'APP_ENV=production',
            'APP_DEBUG=false',
            'APP_KEY=base64:'.base64_encode(random_bytes(32)),
            'APP_URL=http://localhost:8080',
            'CACHE_STORE=array',
            'LOG_CHANNEL=stderr',
            'QUEUE_CONNECTION=sync',
            'SESSION_DRIVER=array',
            'NEXUS_RUNTIME=standalone',
            'NEXUS_RELEASE_ID=01K2AAAAAAAAAAAAAAAAAAAAAA',
            'NEXUS_IMAGE_DIGEST=sha256:'.str_repeat('b', 64),
        ])."\n"));

        $container = 'nexus-ams-web-'.$this->suffix();
        $this->containers[] = $container;
        $this->runProcess([
            'docker',
            'run',
            '--detach',
            '--name',
            $container,
            '--env-file',
            $this->environmentFile,
            '--read-only',
            '--cap-drop',
            'ALL',
            '--security-opt',
            'no-new-privileges:true',
            '--pids-limit',
            '128',
            '--network',
            'none',
            '--tmpfs',
            '/tmp:rw,nosuid,nodev,noexec,mode=1777',
            '--tmpfs',
            '/var/www/html/bootstrap/cache:rw,nosuid,nodev,noexec,uid=33,gid=33,mode=0770',
            '--tmpfs',
            '/var/www/html/storage:rw,nosuid,nodev,noexec,uid=33,gid=33,mode=0770',
            $this->imageTag,
            'web',
        ]);

        $this->waitUntilHealthy($container);
        $startedAt = microtime(true);
        $this->runProcess(['docker', 'stop', '--time', '30', $container], timeout: 40);
        $this->assertLessThan(30.0, microtime(true) - $startedAt);
        $exitCode = trim($this->runProcess([
            'docker',
            'inspect',
            $container,
            '--format',
            '{{.State.ExitCode}}',
        ])->getOutput());
        $this->assertSame('0', $exitCode);
    }

    private function waitUntilHealthy(string $container): void
    {
        $deadline = microtime(true) + 90;
        $lastStatus = 'unknown';

        do {
            $process = $this->runProcessIgnoringFailure([
                'docker',
                'inspect',
                $container,
                '--format',
                '{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}',
            ]);
            $lastStatus = trim($process->getOutput());

            if ($lastStatus === 'healthy') {
                return;
            }

            if (in_array($lastStatus, ['exited', 'dead', 'unhealthy'], true)) {
                break;
            }

            usleep(500_000);
        } while (microtime(true) < $deadline);

        $logs = $this->runProcessIgnoringFailure(['docker', 'logs', '--tail', '100', $container]);
        $this->fail(sprintf(
            'Production web container did not become healthy (status: %s). Logs: %s',
            $lastStatus,
            trim($logs->getOutput()."\n".$logs->getErrorOutput()),
        ));
    }

    /** @param list<string> $command */
    private function runProcess(array $command, int $timeout = 120): Process
    {
        $process = new Process($command, $this->projectRoot);
        $process->setTimeout($timeout);
        $process->mustRun();

        return $process;
    }

    /** @param list<string> $command */
    private function runProcessIgnoringFailure(array $command): Process
    {
        $process = new Process($command, $this->projectRoot);
        $process->setTimeout(60);
        $process->run();

        return $process;
    }

    private function suffix(): string
    {
        return strtolower(substr((string) Str::ulid(), -12));
    }
}
