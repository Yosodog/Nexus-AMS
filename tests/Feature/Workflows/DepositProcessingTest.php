<?php

namespace Tests\Feature\Workflows;

use App\GraphQL\Models\BankRecord;
use App\Models\Account;
use App\Models\DepositRequest;
use App\Models\Nation;
use App\Models\User;
use App\Notifications\DepositCompletedNotification;
use App\Services\DepositService;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Mockery;
use Tests\TestCase;

class DepositProcessingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        SettingService::setLastScannedBankRecordId(0);
    }

    public function test_matching_bank_record_completes_a_pending_deposit_request(): void
    {
        [, $account] = $this->createNationAccountAndUser(779001);
        $deposit = DepositRequest::query()->create([
            'account_id' => $account->id,
            'deposit_code' => 'MATCH123',
            'status' => 'pending',
            'pending_key' => 1,
        ]);

        $this->mockAllianceDeposits([
            $this->makeBankRecord(10, 779001, 777, 'MATCH123', ['money' => 125000, 'food' => 450]),
        ]);

        DepositService::processDeposits(777);

        $deposit->refresh();
        $account->refresh();

        $this->assertSame('completed', $deposit->status);
        $this->assertNull($deposit->pending_key);
        $this->assertSame(10, $deposit->fulfilled_bank_record_id);
        $this->assertSame(125000.0, (float) $account->money);
        $this->assertSame(450.0, (float) $account->food);
        $this->assertDatabaseHas('transactions', [
            'to_account_id' => $account->id,
            'transaction_type' => 'deposit',
            'money' => 125000,
            'food' => 450,
            'is_pending' => 0,
        ]);
        $this->assertSame(10, SettingService::getLastScannedBankRecordId());

        Notification::assertSentOnDemand(
            DepositCompletedNotification::class,
            function (DepositCompletedNotification $notification, array $channels, object $notifiable): bool {
                $payload = $notification->toPNW(new \stdClass);

                return in_array('pnw', $channels, true)
                    && $payload['nation_id'] === 779001
                    && $payload['subject'] === 'Deposit Confirmed'
                    && str_contains($payload['message'], 'Money: $125,000.00')
                    && str_contains($payload['message'], 'Food: 450.00');
            }
        );
    }

    public function test_wrong_receiver_closes_the_request_without_crediting_the_account(): void
    {
        [, $account] = $this->createNationAccountAndUser(779002);
        $deposit = DepositRequest::query()->create([
            'account_id' => $account->id,
            'deposit_code' => 'WRONG777',
            'status' => 'pending',
            'pending_key' => 1,
        ]);

        $this->mockAllianceDeposits([
            $this->makeBankRecord(11, 779002, 999, 'WRONG777', ['money' => 5000]),
        ]);

        DepositService::processDeposits(777);

        $deposit->refresh();
        $account->refresh();

        $this->assertSame('completed', $deposit->status);
        $this->assertNull($deposit->pending_key);
        $this->assertNull($deposit->fulfilled_bank_record_id);
        $this->assertSame(0.0, (float) $account->money);
        $this->assertDatabaseCount('transactions', 0);
        Notification::assertNothingSent();
    }

    public function test_missing_account_closes_the_request_safely(): void
    {
        [, $account] = $this->createNationAccountAndUser(779003);
        $deposit = DepositRequest::query()->create([
            'account_id' => $account->id,
            'deposit_code' => 'MISSING1',
            'status' => 'pending',
            'pending_key' => 1,
        ]);

        $account->delete();

        $this->mockAllianceDeposits([
            $this->makeBankRecord(12, 779003, 777, 'MISSING1', ['money' => 1000]),
        ]);

        DepositService::processDeposits(777);

        $deposit->refresh();

        $this->assertSame('completed', $deposit->status);
        $this->assertNull($deposit->pending_key);
        $this->assertNull($deposit->fulfilled_bank_record_id);
        $this->assertDatabaseCount('transactions', 0);
        Notification::assertNothingSent();
    }

    public function test_processing_the_same_bank_record_twice_is_idempotent(): void
    {
        [, $account] = $this->createNationAccountAndUser(779004);
        DepositRequest::query()->create([
            'account_id' => $account->id,
            'deposit_code' => 'ONCEONLY',
            'status' => 'pending',
            'pending_key' => 1,
        ]);

        $record = $this->makeBankRecord(13, 779004, 777, 'ONCEONLY', ['money' => 2500]);
        $mock = Mockery::mock('alias:App\Services\BankRecordQueryService');
        $mock->shouldReceive('getAllianceDeposits')->once()->andReturn([$record]);

        DepositService::processDeposits(777);
        DepositService::processDeposits(777);

        $account->refresh();

        $this->assertSame(2500.0, (float) $account->money);
        $this->assertDatabaseCount('transactions', 1);
        Notification::assertSentOnDemandTimes(DepositCompletedNotification::class, 1);
    }

    /**
     * @return array{0: User, 1: Account}
     */
    private function createNationAccountAndUser(int $nationId): array
    {
        $nation = Nation::factory()->create([
            'id' => $nationId,
        ]);

        $user = User::factory()->verified()->create([
            'nation_id' => $nation->id,
        ]);

        $account = new Account;
        $account->nation_id = $nation->id;
        $account->name = 'Primary';
        $account->save();

        return [$user, $account];
    }

    /**
     * @param  array<int, BankRecord>  $records
     */
    private function mockAllianceDeposits(array $records): void
    {
        $mock = Mockery::mock('alias:App\Services\BankRecordQueryService');
        $mock->shouldReceive('getAllianceDeposits')->once()->andReturn($records);
    }

    /**
     * @param  array<string, float|int>  $resources
     */
    private function makeBankRecord(int $id, int $senderId, int $receiverId, string $note, array $resources = []): BankRecord
    {
        $record = new BankRecord;
        $record->id = $id;
        $record->date = now()->toDateString();
        $record->sender_id = $senderId;
        $record->sender_type = 1;
        $record->receiver_id = $receiverId;
        $record->receiver_type = 2;
        $record->banker_id = 0;
        $record->note = $note;

        foreach (['money', 'coal', 'oil', 'uranium', 'iron', 'bauxite', 'lead', 'gasoline', 'munitions', 'steel', 'aluminum', 'food'] as $resource) {
            $record->{$resource} = (float) ($resources[$resource] ?? 0);
        }

        $record->tax_id = 0;

        return $record;
    }
}
