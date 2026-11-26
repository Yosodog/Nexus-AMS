<?php

namespace Tests\Unit\Config;

use PDO;
use Pdo\Mysql;
use Tests\TestCase;

class DatabaseEmulatedPreparesConfigTest extends TestCase
{
    public function test_emulated_prepares_are_off_by_default(): void
    {
        $config = $this->loadConfigWith(null);

        foreach (['mysql', 'mariadb'] as $connection) {
            $this->assertArrayNotHasKey(PDO::ATTR_EMULATE_PREPARES, $config['connections'][$connection]['options']);
            $this->assertArrayNotHasKey($this->multiStatementsOption(), $config['connections'][$connection]['options']);
        }
    }

    public function test_emulated_prepares_follow_the_env_variable(): void
    {
        $config = $this->loadConfigWith('true');

        foreach (['mysql', 'mariadb'] as $connection) {
            $options = $config['connections'][$connection]['options'];

            $this->assertTrue($options[PDO::ATTR_EMULATE_PREPARES]);
            $this->assertFalse($options[$this->multiStatementsOption()]);
        }
    }

    public function test_false_value_keeps_native_prepares(): void
    {
        $config = $this->loadConfigWith('false');

        $this->assertArrayNotHasKey(PDO::ATTR_EMULATE_PREPARES, $config['connections']['mysql']['options']);
    }

    /**
     * @return array<string, mixed>
     */
    private function loadConfigWith(?string $value): array
    {
        $original = getenv('DB_EMULATE_PREPARES');

        try {
            $this->setEnv($value);

            return require dirname(__DIR__, 3).'/config/database.php';
        } finally {
            $this->setEnv($original === false ? null : $original);
        }
    }

    private function setEnv(?string $value): void
    {
        if ($value === null) {
            putenv('DB_EMULATE_PREPARES');
            unset($_ENV['DB_EMULATE_PREPARES'], $_SERVER['DB_EMULATE_PREPARES']);

            return;
        }

        putenv('DB_EMULATE_PREPARES='.$value);
        $_ENV['DB_EMULATE_PREPARES'] = $value;
        $_SERVER['DB_EMULATE_PREPARES'] = $value;
    }

    private function multiStatementsOption(): int
    {
        return defined('Pdo\\Mysql::ATTR_MULTI_STATEMENTS')
            ? Mysql::ATTR_MULTI_STATEMENTS
            : PDO::MYSQL_ATTR_MULTI_STATEMENTS;
    }
}
