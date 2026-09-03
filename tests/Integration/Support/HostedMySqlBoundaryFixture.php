<?php

declare(strict_types=1);

namespace Tests\Integration\Support;

use PDO;
use PDOException;
use RuntimeException;

final class HostedMySqlBoundaryFixture
{
    private const string DEFINER_HOST = 'localhost';

    private string $worldSchema;

    private string $otherTenantSchema;

    private string $privateTable;

    private string $applicationUser;

    private string $applicationHost;

    private string $definerUser;

    private string $applicationPassword;

    private string $definerPassword;

    public function __construct(
        private readonly PDO $admin,
        private readonly string $tenantSchema,
        private readonly string $connectionHost,
        private readonly int $connectionPort,
    ) {
        $this->assertIdentifier($this->tenantSchema);

        $suffix = bin2hex(random_bytes(6));
        $this->worldSchema = "nxc2_world_{$suffix}";
        $this->otherTenantSchema = "nxc2_other_{$suffix}";
        $this->privateTable = "phase2_private_{$suffix}";
        $this->applicationUser = "nxc2_app_{$suffix}";
        $this->definerUser = "nxc2_definer_{$suffix}";
        $this->applicationPassword = bin2hex(random_bytes(32));
        $this->definerPassword = bin2hex(random_bytes(32));
        $this->applicationHost = $this->clientHost();

        $this->assertIdentifier($this->worldSchema);
        $this->assertIdentifier($this->otherTenantSchema);
        $this->assertIdentifier($this->privateTable);
        $this->assertIdentifier($this->applicationUser);
        $this->assertIdentifier($this->definerUser);

        if ($this->connectionHost === '' || $this->connectionPort < 1 || $this->connectionPort > 65535) {
            throw new RuntimeException('The hosted MySQL connection profile is invalid.');
        }
    }

    public function install(): void
    {
        $this->admin->exec(
            'CREATE DATABASE '.$this->identifier($this->worldSchema)
            .' CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci',
        );
        $this->admin->exec(
            'CREATE DATABASE '.$this->identifier($this->otherTenantSchema)
            .' CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci',
        );
        $this->admin->exec(
            'CREATE TABLE '.$this->qualified($this->worldSchema, 'alliances').' ('
            .'`id` BIGINT UNSIGNED NOT NULL PRIMARY KEY, '
            .'`name` VARCHAR(255) NOT NULL, '
            .'`private_canary` VARCHAR(255) NOT NULL'
            .') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci',
        );
        $this->admin->exec(
            'INSERT INTO '.$this->qualified($this->worldSchema, 'alliances')
            .' (`id`, `name`, `private_canary`) VALUES (1001, \'Hosted Alliance\', \'world-private-canary\')',
        );
        $this->admin->exec(
            'CREATE TABLE '.$this->qualified($this->otherTenantSchema, 'private_records').' ('
            .'`id` BIGINT UNSIGNED NOT NULL PRIMARY KEY, '
            .'`private_value` VARCHAR(255) NOT NULL'
            .') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci',
        );
        $this->admin->exec(
            'INSERT INTO '.$this->qualified($this->otherTenantSchema, 'private_records')
            .' (`id`, `private_value`) VALUES (1, \'other-tenant-private-value\')',
        );
        $this->admin->exec(
            'CREATE TABLE '.$this->qualified($this->tenantSchema, $this->privateTable).' ('
            .'`id` BIGINT UNSIGNED NOT NULL PRIMARY KEY, '
            .'`private_value` VARCHAR(255) NOT NULL'
            .') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci',
        );
        $this->admin->exec(
            'CREATE USER '.$this->account($this->definerUser, self::DEFINER_HOST)
            .' IDENTIFIED BY '.$this->literal($this->definerPassword),
        );
        $this->admin->exec(
            'ALTER USER '.$this->account($this->definerUser, self::DEFINER_HOST).' ACCOUNT LOCK',
        );
        $this->admin->exec(
            'GRANT SELECT (`id`, `name`) ON '.$this->qualified($this->worldSchema, 'alliances')
            .' TO '.$this->account($this->definerUser, self::DEFINER_HOST),
        );
        $this->admin->exec('DROP VIEW IF EXISTS '.$this->qualified($this->tenantSchema, 'alliances'));
        $this->admin->exec(
            'CREATE ALGORITHM = MERGE DEFINER = '.$this->account($this->definerUser, self::DEFINER_HOST)
            .' SQL SECURITY DEFINER VIEW '.$this->qualified($this->tenantSchema, 'alliances')
            .' AS SELECT `id`, `name` FROM '.$this->qualified($this->worldSchema, 'alliances'),
        );
        $this->admin->exec(
            'CREATE USER '.$this->account($this->applicationUser, $this->applicationHost)
            .' IDENTIFIED BY '.$this->literal($this->applicationPassword),
        );
        $this->admin->exec(
            'GRANT SELECT, INSERT, UPDATE, DELETE ON '
            .$this->qualified($this->tenantSchema, $this->privateTable)
            .' TO '.$this->account($this->applicationUser, $this->applicationHost),
        );
        $this->admin->exec(
            'GRANT SELECT ON '.$this->qualified($this->tenantSchema, 'alliances')
            .' TO '.$this->account($this->applicationUser, $this->applicationHost),
        );
    }

    public function applicationConnection(): PDO
    {
        return new PDO(
            'mysql:host='.$this->connectionHost.';port='.$this->connectionPort
            .';dbname='.$this->tenantSchema.';charset=utf8mb4',
            $this->applicationUser,
            $this->applicationPassword,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ],
        );
    }

    /**
     * @return list<string>
     */
    public function applicationGrants(): array
    {
        return $this->showGrants($this->applicationUser, $this->applicationHost);
    }

    /**
     * @return list<string>
     */
    public function definerGrants(): array
    {
        return $this->showGrants($this->definerUser, self::DEFINER_HOST);
    }

    /**
     * @return array{definer: string, security_type: string, view_definition: string}
     */
    public function viewMetadata(): array
    {
        $statement = $this->admin->prepare(
            'SELECT DEFINER AS definer, SECURITY_TYPE AS security_type, VIEW_DEFINITION AS view_definition '
            .'FROM information_schema.VIEWS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
        );
        $statement->execute([$this->tenantSchema, 'alliances']);
        $metadata = $statement->fetch(PDO::FETCH_ASSOC);

        if (! is_array($metadata)) {
            throw new RuntimeException('The hosted world view metadata was not found.');
        }

        return [
            'definer' => (string) ($metadata['definer'] ?? ''),
            'security_type' => (string) ($metadata['security_type'] ?? ''),
            'view_definition' => (string) ($metadata['view_definition'] ?? ''),
        ];
    }

    public function applicationHost(): string
    {
        return $this->applicationHost;
    }

    public function applicationUser(): string
    {
        return $this->applicationUser;
    }

    public function definerUser(): string
    {
        return $this->definerUser;
    }

    public function worldSchema(): string
    {
        return $this->worldSchema;
    }

    public function otherTenantSchema(): string
    {
        return $this->otherTenantSchema;
    }

    public function privateTable(): string
    {
        return $this->privateTable;
    }

    public function definerIsLocked(): bool
    {
        $statement = $this->admin->prepare(
            'SELECT account_locked FROM mysql.user WHERE User = ? AND Host = ?',
        );
        $statement->execute([$this->definerUser, self::DEFINER_HOST]);

        return $statement->fetchColumn() === 'Y';
    }

    /**
     * @return list<string>
     */
    public function applicationAccountHosts(): array
    {
        $statement = $this->admin->prepare('SELECT Host FROM mysql.user WHERE User = ? ORDER BY Host');
        $statement->execute([$this->applicationUser]);

        return array_map(
            static fn (mixed $host): string => (string) $host,
            $statement->fetchAll(PDO::FETCH_COLUMN),
        );
    }

    public function cleanup(): void
    {
        $cleanupStatements = [
            'DROP VIEW IF EXISTS '.$this->qualified($this->tenantSchema, 'alliances'),
            'DROP TABLE IF EXISTS '.$this->qualified($this->tenantSchema, $this->privateTable),
            'DROP USER IF EXISTS '.$this->account($this->applicationUser, $this->applicationHost),
            'DROP USER IF EXISTS '.$this->account($this->definerUser, self::DEFINER_HOST),
            'DROP DATABASE IF EXISTS '.$this->identifier($this->worldSchema),
            'DROP DATABASE IF EXISTS '.$this->identifier($this->otherTenantSchema),
        ];
        $errors = [];

        foreach ($cleanupStatements as $statement) {
            try {
                $this->admin->exec($statement);
            } catch (PDOException $exception) {
                $errors[] = $exception;
            }
        }

        if ($errors !== []) {
            throw new RuntimeException(
                'The hosted MySQL boundary fixture did not clean up exactly.',
                0,
                $errors[0],
            );
        }

        $this->assertNoLeftovers();
    }

    private function assertNoLeftovers(): void
    {
        $statement = $this->admin->prepare(
            'SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME IN (?, ?)',
        );
        $statement->execute([$this->worldSchema, $this->otherTenantSchema]);

        if ((int) $statement->fetchColumn() !== 0) {
            throw new RuntimeException('The hosted MySQL boundary schemas were not removed.');
        }

        $statement = $this->admin->prepare(
            'SELECT COUNT(*) FROM mysql.user WHERE (User = ? AND Host = ?) OR (User = ? AND Host = ?)',
        );
        $statement->execute([
            $this->applicationUser,
            $this->applicationHost,
            $this->definerUser,
            self::DEFINER_HOST,
        ]);

        if ((int) $statement->fetchColumn() !== 0) {
            throw new RuntimeException('The hosted MySQL boundary accounts were not removed.');
        }

        $statement = $this->admin->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME IN (?, ?)',
        );
        $statement->execute([$this->tenantSchema, 'alliances', $this->privateTable]);

        if ((int) $statement->fetchColumn() !== 0) {
            throw new RuntimeException('The hosted MySQL tenant resources were not removed.');
        }
    }

    private function clientHost(): string
    {
        $statement = $this->admin->query("SELECT SUBSTRING_INDEX(USER(), '@', -1)");
        $host = $statement === false ? null : $statement->fetchColumn();

        if (! is_string($host) || $host === '' || strpbrk($host, '%_') !== false) {
            throw new RuntimeException('MySQL did not report an exact hosted application host.');
        }

        return $host;
    }

    /**
     * @return list<string>
     */
    private function showGrants(string $user, string $host): array
    {
        $statement = $this->admin->query('SHOW GRANTS FOR '.$this->account($user, $host));

        if ($statement === false) {
            throw new RuntimeException('The hosted MySQL grant inventory is unavailable.');
        }

        return array_map(
            static fn (mixed $grant): string => (string) $grant,
            $statement->fetchAll(PDO::FETCH_COLUMN),
        );
    }

    private function qualified(string $schema, string $object): string
    {
        return $this->identifier($schema).'.'.$this->identifier($object);
    }

    private function account(string $user, string $host): string
    {
        return $this->literal($user).'@'.$this->literal($host);
    }

    private function identifier(string $identifier): string
    {
        $this->assertIdentifier($identifier);

        return '`'.$identifier.'`';
    }

    private function literal(string $value): string
    {
        $quoted = $this->admin->quote($value, PDO::PARAM_STR);

        if ($quoted === false) {
            throw new RuntimeException('The hosted MySQL fixture could not quote a literal.');
        }

        return $quoted;
    }

    private function assertIdentifier(string $identifier): void
    {
        if (! preg_match('/\A[a-z0-9_]+\z/', $identifier)) {
            throw new RuntimeException('The hosted MySQL fixture received an invalid identifier.');
        }
    }
}
