<?php

namespace Tests\Feature;

use App\Exceptions\UserErrorException;
use App\Services\AccountService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BlockadeWithdrawalSafetyTest extends TestCase
{
    public function test_withdrawal_is_blocked_when_blockade_status_cannot_be_checked(): void
    {
        config(['services.pw.api_key' => null]);

        $this->expectException(UserErrorException::class);
        $this->expectExceptionMessage(
            'We could not verify your blockade status right now. No funds were moved; please try again shortly.'
        );

        AccountService::ensureNotBlockaded(820001);
    }

    public function test_withdrawal_is_allowed_when_a_successful_response_contains_no_blockade(): void
    {
        $this->fakeWarResponse([]);

        AccountService::ensureNotBlockaded(820002);

        $this->addToAssertionCount(1);
        Http::assertSentCount(1);
    }

    public function test_withdrawal_is_blocked_when_the_requesting_nation_is_blockaded(): void
    {
        $this->fakeWarResponse([[
            'id' => 900001,
            'att_id' => 820003,
            'def_id' => 820004,
            'naval_blockade' => 820004,
        ]]);

        $this->expectException(UserErrorException::class);
        $this->expectExceptionMessage('Withdrawals are disabled while your nation is under naval blockade.');

        AccountService::ensureNotBlockaded(820003);
    }

    /** @param array<int, array<string, int>> $wars */
    private function fakeWarResponse(array $wars): void
    {
        config(['services.pw.api_key' => 'test-api-key']);

        Http::fake([
            '*' => Http::response([
                'data' => [
                    'wars' => [
                        'data' => $wars,
                        'paginatorInfo' => [
                            'perPage' => 1000,
                            'count' => count($wars),
                            'lastPage' => 1,
                        ],
                    ],
                ],
            ]),
        ]);
    }
}
