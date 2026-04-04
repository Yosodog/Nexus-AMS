<?php

namespace Tests\Unit\Services;

use App\Models\Account;
use App\Models\Nation;
use App\Models\WarAidRequest;
use App\Services\SettingService;
use App\Services\WarAidService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\FeatureTestCase;

class WarAidServiceTest extends FeatureTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        cache()->forever('alliances:membership:ids', [777]);
        SettingService::setWarAidEnabled(true);
    }

    public function test_submit_aid_request_rejects_foreign_accounts_without_persisting_requests(): void
    {
        $nation = Nation::factory()->create([
            'alliance_id' => 777,
            'alliance_position' => 'MEMBER',
        ]);
        $foreignAccount = $this->createAccount(Nation::factory()->create());

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('You do not own the selected account.');

        app(WarAidService::class)->submitAidRequest($nation, [
            'account_id' => $foreignAccount->id,
            'money' => 500000,
        ]);
    }

    public function test_approve_aid_request_rejects_non_pending_requests_before_mutation(): void
    {
        $request = WarAidRequest::query()->create([
            'nation_id' => Nation::factory()->create()->id,
            'account_id' => $this->createAccount(Nation::factory()->create())->id,
            'status' => 'approved',
            'money' => 1000,
            'note' => '',
            'pending_key' => null,
        ]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Only pending war aid requests can be approved.');

        app(WarAidService::class)->approveAidRequest($request, ['money' => 2000]);
    }

    public function test_deny_aid_request_rejects_non_pending_requests_before_mutation(): void
    {
        $request = WarAidRequest::query()->create([
            'nation_id' => Nation::factory()->create()->id,
            'account_id' => $this->createAccount(Nation::factory()->create())->id,
            'status' => 'denied',
            'money' => 1000,
            'note' => '',
            'pending_key' => null,
        ]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Only pending war aid requests can be denied.');

        app(WarAidService::class)->denyAidRequest($request);
    }

    private function createAccount(Nation $nation): Account
    {
        $account = new Account;
        $account->nation_id = $nation->id;
        $account->name = 'Primary';
        $account->save();

        return $account;
    }
}
