<?php

namespace Tests\Unit\Services;

use App\Exceptions\AmbiguousMutationOutcomeException;
use App\Services\BankService;
use Illuminate\Support\Facades\Http;
use Tests\FeatureTestCase;

class BankServiceTest extends FeatureTestCase
{
    public function test_send_withdraw_rejects_record_with_wrong_receiver_type(): void
    {
        Http::fake([
            '*' => Http::response([
                'data' => [
                    'bankWithdraw' => [
                        'id' => 987654,
                        'date' => now()->toISOString(),
                        'sender_id' => 1,
                        'sender_type' => 2,
                        'receiver_id' => 123,
                        'receiver_type' => 2,
                        'banker_id' => 1,
                        'note' => 'Withdrawal',
                        'money' => 100,
                    ],
                ],
            ], 200),
        ]);

        $service = new BankService;
        $service->receiver = 123;
        $service->receiver_type = 1;
        $service->money = 100;

        $this->expectException(AmbiguousMutationOutcomeException::class);
        $this->expectExceptionMessage('could not be matched');

        $service->sendWithdraw();
    }
}
