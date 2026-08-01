<?php

namespace Tests\Unit;

use Tests\TestCase;

class SanctumStatefulDomainsTest extends TestCase
{
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
            if ($original === false) {
                putenv('SANCTUM_STATEFUL_DOMAINS');
                unset($_ENV['SANCTUM_STATEFUL_DOMAINS'], $_SERVER['SANCTUM_STATEFUL_DOMAINS']);
            } else {
                putenv("SANCTUM_STATEFUL_DOMAINS={$original}");
                $_ENV['SANCTUM_STATEFUL_DOMAINS'] = $original;
                $_SERVER['SANCTUM_STATEFUL_DOMAINS'] = $original;
            }
        }
    }
}
