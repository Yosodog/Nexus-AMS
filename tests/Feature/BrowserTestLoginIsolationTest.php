<?php

namespace Tests\Feature;

use App\Services\CityCostService;
use App\Support\BrowserTestBootstrap;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BrowserTestLoginIsolationTest extends TestCase
{
    public function test_browser_test_login_is_not_available_to_remote_clients(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->get('/_browser/login/admin')
            ->assertNotFound();
    }

    public function test_browser_city_grant_pages_use_seeded_prices_without_external_requests(): void
    {
        $database = tempnam(sys_get_temp_dir(), 'nexus-browser-test-');
        $this->assertNotFalse($database);

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', $database);
        config()->set('services.pw.alliance_id', 9001);
        DB::purge('sqlite');
        Http::fake();

        try {
            $users = app(BrowserTestBootstrap::class)->resetAndSeed();

            $this->assertSame(15.0, app(CityCostService::class)->getTop20Average(refreshIfStale: false));

            $this->actingAs($users['member'])->get(route('grants.city'))->assertOk();
            $this->actingAs($users['admin'])->get('/admin/grants/city')->assertOk();

            Http::assertNothingSent();
        } finally {
            DB::purge('sqlite');
            unlink($database);
        }
    }
}
