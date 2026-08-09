<?php

declare(strict_types=1);

namespace Tests\Integration;

use Closure;
use Illuminate\Support\Str;
use Redis;
use RedisException;
use Tests\TestCase;

class TenantEventRedisAclTest extends TestCase
{
    private ?Redis $admin = null;

    /** @var list<string> */
    private array $keys = [];

    /** @var list<string> */
    private array $users = [];

    protected function tearDown(): void
    {
        if ($this->admin !== null) {
            foreach ($this->keys as $key) {
                $this->admin->rawCommand('DEL', $key);
            }

            foreach ($this->users as $user) {
                $this->admin->rawCommand('ACL', 'DELUSER', $user);
            }

            $this->admin->close();
        }

        parent::tearDown();
    }

    public function test_real_redis_7_acl_allows_only_the_bound_tenant_stream_commands(): void
    {
        $adminUrl = env('TENANT_EVENTS_REDIS_ACL_ADMIN_URL');

        if (! is_string($adminUrl) || $adminUrl === '') {
            $this->markTestSkipped('Set TENANT_EVENTS_REDIS_ACL_ADMIN_URL for the disposable Redis 7 ACL proof.');
        }

        $this->admin = $this->connect($adminUrl);
        $server = $this->admin->info('server');
        $version = is_array($server) ? ($server['redis_version'] ?? '') : '';
        $this->assertMatchesRegularExpression('/\A(?:7|8|9)\./', (string) $version);

        $suffix = strtolower(substr((string) Str::ulid(), -10));
        $tenantA = (string) Str::ulid();
        $tenantB = (string) Str::ulid();
        $streamA = 'nexus:tenant-events:v1:'.$tenantA;
        $streamB = 'nexus:tenant-events:v1:'.$tenantB;
        $legacyStream = 'nexus:subscriptions:v1';
        $userA = 'tenant_events_a_'.$suffix;
        $userB = 'tenant_events_b_'.$suffix;
        $passwordA = bin2hex(random_bytes(24));
        $passwordB = bin2hex(random_bytes(24));
        $this->keys = [$streamA, $streamB, $legacyStream];
        $this->users = [$userA, $userB];

        $this->admin->rawCommand('XADD', $streamA, '*', 'body', '{}');
        $this->admin->rawCommand('XADD', $streamB, '*', 'body', '{}');
        $this->admin->rawCommand('XADD', $legacyStream, '*', 'body', '{}');
        $this->createTenantUser($userA, $passwordA, $streamA);
        $this->createTenantUser($userB, $passwordB, $streamB);

        $tenantAClient = $this->connect($adminUrl, $userA, $passwordA);
        $tenantBClient = $this->connect($adminUrl, $userB, $passwordB);

        try {
            $this->assertTrue((bool) $tenantAClient->ping());
            $this->assertTrue((bool) $tenantBClient->ping());
            $this->assertTrue((bool) $tenantAClient->rawCommand(
                'XGROUP',
                'CREATE',
                $streamA,
                'nexus-ams-v1',
                '0',
            ));
            $messages = $tenantAClient->rawCommand(
                'XREADGROUP',
                'GROUP',
                'nexus-ams-v1',
                'acl-proof',
                'COUNT',
                1,
                'STREAMS',
                $streamA,
                '>',
            );
            $this->assertIsArray($messages);
            $pending = $tenantAClient->rawCommand('XPENDING', $streamA, 'nexus-ams-v1');
            $this->assertIsArray($pending);
            $this->assertSame(1, (int) ($pending[0] ?? 0));

            $streamId = $messages[0][1][0][0] ?? null;
            $this->assertIsString($streamId);
            $this->assertSame(1, $tenantAClient->rawCommand(
                'XACK',
                $streamA,
                'nexus-ams-v1',
                $streamId,
            ));

            $this->admin->rawCommand('XADD', $streamA, '*', 'body', '{"retry":true}');
            $tenantAClient->rawCommand(
                'XREADGROUP',
                'GROUP',
                'nexus-ams-v1',
                'abandoned',
                'COUNT',
                1,
                'STREAMS',
                $streamA,
                '>',
            );
            $claimed = $tenantAClient->rawCommand(
                'XAUTOCLAIM',
                $streamA,
                'nexus-ams-v1',
                'reclaimer',
                0,
                '0-0',
                'COUNT',
                1,
            );
            $this->assertIsArray($claimed);
            $this->assertNotEmpty($claimed[1] ?? []);

            $this->assertDenied(fn (): mixed => $tenantAClient->rawCommand(
                'XREADGROUP',
                'GROUP',
                'nexus-ams-v1',
                'acl-proof',
                'STREAMS',
                $streamB,
                '>',
            ));
            $this->assertDenied(fn (): mixed => $tenantBClient->rawCommand(
                'XGROUP',
                'CREATE',
                $streamA,
                'wrong-tenant',
                '0',
            ));
            $this->assertDenied(fn (): mixed => $tenantAClient->rawCommand(
                'XGROUP',
                'CREATE',
                $legacyStream,
                'legacy',
                '0',
            ));
            $this->assertDenied(fn (): mixed => $tenantAClient->rawCommand(
                'XADD',
                $streamA,
                '*',
                'body',
                '{}',
            ));
            $this->assertDenied(fn (): mixed => $tenantAClient->rawCommand('XDEL', $streamA, $streamId));
            $this->assertDenied(fn (): mixed => $tenantAClient->rawCommand('GET', $streamA));
        } finally {
            $tenantAClient->close();
            $tenantBClient->close();
        }
    }

    private function createTenantUser(string $user, string $password, string $stream): void
    {
        $result = $this->admin?->rawCommand(
            'ACL',
            'SETUSER',
            $user,
            'reset',
            'on',
            '>'.$password,
            '~'.$stream,
            '-@all',
            '+ping',
            '+select',
            '+xgroup|create',
            '+xreadgroup',
            '+xautoclaim',
            '+xpending',
            '+xack',
        );

        $this->assertTrue((bool) $result);
    }

    private function connect(string $url, ?string $user = null, ?string $password = null): Redis
    {
        $parts = parse_url($url);
        $this->assertIsArray($parts);
        $host = $parts['host'] ?? null;
        $port = $parts['port'] ?? 6379;
        $this->assertIsString($host);
        $client = new Redis;
        $this->assertTrue($client->connect($host, (int) $port, 2.0));

        $configuredUser = $user ?? ($parts['user'] ?? null);
        $configuredPassword = $password ?? ($parts['pass'] ?? null);

        if (is_string($configuredPassword) && $configuredPassword !== '') {
            $authenticated = is_string($configuredUser) && $configuredUser !== ''
                ? $client->auth([$configuredUser, $configuredPassword])
                : $client->auth($configuredPassword);
            $this->assertTrue($authenticated);
        }

        $database = isset($parts['path']) ? (int) trim($parts['path'], '/') : 0;
        $this->assertTrue($client->select($database));

        return $client;
    }

    private function assertDenied(Closure $operation): void
    {
        try {
            $operation();
            $this->fail('The tenant Redis ACL allowed an out-of-scope key or command.');
        } catch (RedisException $exception) {
            $this->assertStringContainsString('NOPERM', $exception->getMessage());
        }
    }
}
