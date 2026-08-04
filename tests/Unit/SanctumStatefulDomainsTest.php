<?php

namespace Tests\Unit;

use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SanctumStatefulDomainsTest extends TestCase
{
    public function test_generated_stateful_domains_normalize_sanctum_helper_values(): void
    {
        $originalAppUrl = config('app.url');
        $originalStatefulDomains = getenv('SANCTUM_STATEFUL_DOMAINS');

        try {
            config()->set('app.url', 'https://bkpw.net');
            putenv('SANCTUM_STATEFUL_DOMAINS');
            unset($_ENV['SANCTUM_STATEFUL_DOMAINS'], $_SERVER['SANCTUM_STATEFUL_DOMAINS']);

            $sanctum = require dirname(__DIR__, 2).'/config/sanctum.php';

            $this->assertContains('bkpw.net', $sanctum['stateful']);
            $this->assertContains(Sanctum::$currentRequestHostPlaceholder, $sanctum['stateful']);
            $this->assertNotContains(',bkpw.net', $sanctum['stateful']);
            $this->assertNotContains(','.Sanctum::$currentRequestHostPlaceholder, $sanctum['stateful']);
        } finally {
            config()->set('app.url', $originalAppUrl);
            $this->restoreStatefulDomainsEnvironment($originalStatefulDomains);
        }
    }

    public function test_explicit_stateful_domains_replace_generated_defaults(): void
    {
        $original = getenv('SANCTUM_STATEFUL_DOMAINS');

        try {
            putenv('SANCTUM_STATEFUL_DOMAINS=trusted.example.test, api.example.test');
            $_ENV['SANCTUM_STATEFUL_DOMAINS'] = 'trusted.example.test, api.example.test';
            $_SERVER['SANCTUM_STATEFUL_DOMAINS'] = 'trusted.example.test, api.example.test';

            $sanctum = require dirname(__DIR__, 2).'/config/sanctum.php';

            $this->assertSame([
                'trusted.example.test',
                'api.example.test',
            ], $sanctum['stateful']);
        } finally {
            $this->restoreStatefulDomainsEnvironment($original);
        }
    }

    private function restoreStatefulDomainsEnvironment(string|false $value): void
    {
        if ($value === false) {
            putenv('SANCTUM_STATEFUL_DOMAINS');
            unset($_ENV['SANCTUM_STATEFUL_DOMAINS'], $_SERVER['SANCTUM_STATEFUL_DOMAINS']);

            return;
        }

        putenv("SANCTUM_STATEFUL_DOMAINS={$value}");
        $_ENV['SANCTUM_STATEFUL_DOMAINS'] = $value;
        $_SERVER['SANCTUM_STATEFUL_DOMAINS'] = $value;
    }
}
