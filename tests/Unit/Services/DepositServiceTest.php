<?php

namespace Tests\Unit\Services;

use App\Models\Account;
use App\Models\DepositRequest;
use App\Models\Nation;
use App\Services\DepositService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\FeatureTestCase;

class DepositServiceTest extends FeatureTestCase
{
    use RefreshDatabase;

    public function test_create_request_reuses_an_existing_pending_deposit_request(): void
    {
        $account = $this->createAccount();
        $existing = DepositRequest::query()->create([
            'account_id' => $account->id,
            'deposit_code' => 'EXIST123',
            'status' => 'pending',
            'pending_key' => 1,
        ]);

        $deposit = DepositService::createRequest($account);

        $this->assertTrue($deposit->is($existing));
        $this->assertSame('EXIST123', $deposit->deposit_code);
        $this->assertDatabaseCount('deposit_requests', 1);
    }

    public function test_create_request_creates_a_new_pending_request_when_none_exists(): void
    {
        $account = $this->createAccount();

        $deposit = DepositService::createRequest($account);

        $this->assertSame('pending', $deposit->status);
        $this->assertSame(1, $deposit->pending_key);
        $this->assertSame(8, strlen($deposit->deposit_code));
    }

    public function test_set_deposit_completed_marks_request_complete_and_clears_pending_key(): void
    {
        $account = $this->createAccount();
        $deposit = DepositRequest::query()->create([
            'account_id' => $account->id,
            'deposit_code' => 'DONE1234',
            'status' => 'pending',
            'pending_key' => 1,
        ]);

        DepositService::setDepositCompleted($deposit);

        $deposit->refresh();

        $this->assertSame('completed', $deposit->status);
        $this->assertNull($deposit->pending_key);
    }

    private function createAccount(): Account
    {
        $nation = Nation::factory()->create();
        $account = new Account;
        $account->nation_id = $nation->id;
        $account->name = 'Primary';
        $account->save();

        return $account;
    }
}
