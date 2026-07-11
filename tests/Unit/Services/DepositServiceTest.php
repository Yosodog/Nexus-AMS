<?php

namespace Tests\Unit\Services;

use App\Exceptions\UserErrorException;
use App\Models\Account;
use App\Models\DepositRequest;
use App\Models\Nation;
use App\Services\DepositService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
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

    public function test_create_request_rejects_frozen_accounts_even_with_existing_pending_code(): void
    {
        $account = $this->createAccount(frozen: true);
        DepositRequest::query()->create([
            'account_id' => $account->id,
            'deposit_code' => 'FROZEN12',
            'status' => 'pending',
            'pending_key' => 1,
        ]);

        $this->expectException(UserErrorException::class);
        $this->expectExceptionMessage('This account is frozen. Deposits are disabled.');

        DepositService::createRequest($account);
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

    public function test_create_request_expires_codes_after_sixty_minutes(): void
    {
        Carbon::setTestNow('2026-07-10 12:00:00');
        $account = $this->createAccount();
        $first = DepositService::createRequest($account);

        Carbon::setTestNow(now()->addMinutes(61));
        $second = DepositService::createRequest($account);

        $this->assertFalse($first->is($second));
        $this->assertSame('expired', $first->fresh()->status);
        $this->assertNull($first->fresh()->pending_key);
        $this->assertSame('pending', $second->status);

        Carbon::setTestNow();
    }

    public function test_create_request_retries_random_code_collisions(): void
    {
        $account = $this->createAccount();
        $otherAccount = $this->createAccount();
        DepositRequest::query()->create([
            'account_id' => $otherAccount->id,
            'deposit_code' => 'COLLIDE1',
            'status' => 'completed',
        ]);
        Str::createRandomStringsUsingSequence(['COLLIDE1', 'UNIQUE22']);

        try {
            $deposit = DepositService::createRequest($account);
        } finally {
            Str::createRandomStringsNormally();
        }

        $this->assertSame('UNIQUE22', $deposit->deposit_code);
        $this->assertSame('pending', $deposit->status);
    }

    private function createAccount(bool $frozen = false): Account
    {
        $nation = Nation::factory()->create();
        $account = new Account;
        $account->nation_id = $nation->id;
        $account->name = 'Primary';
        $account->frozen = $frozen;
        $account->save();

        return $account;
    }
}
