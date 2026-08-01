<?php

namespace Tests\Feature;

use Tests\TestCase;

class BrowserTestLoginIsolationTest extends TestCase
{
    public function test_browser_test_login_is_not_available_to_remote_clients(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->get('/_browser/login/admin')
            ->assertNotFound();
    }
}
