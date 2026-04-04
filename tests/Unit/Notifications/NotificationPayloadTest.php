<?php

namespace Tests\Unit\Notifications;

use App\Models\Account;
use App\Models\CityGrantRequest;
use App\Models\DepositRequest;
use App\Models\GrantApplication;
use App\Models\Grants;
use App\Models\Loan;
use App\Models\RebuildingRequest;
use App\Models\WarAidRequest;
use App\Notifications\CityGrantNotification;
use App\Notifications\DepositCompletedNotification;
use App\Notifications\DepositCreated;
use App\Notifications\GrantNotification;
use App\Notifications\LoanNotification;
use App\Notifications\RebuildingNotification;
use App\Notifications\WarAidNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\FeatureTestCase;

class NotificationPayloadTest extends FeatureTestCase
{
    use RefreshDatabase;

    public function test_grant_notification_renders_approved_payload(): void
    {
        $grant = new Grants;
        $grant->name = 'Growth Grant';
        $application = new GrantApplication(['status' => 'approved']);
        $application->setRelation('grant', $grant);

        $payload = (new GrantNotification(1, $application, 'approved'))->toPNW(new \stdClass);

        $this->assertSame('Grant Approved!', $payload['subject']);
        $this->assertStringContainsString('Growth Grant', $payload['message']);
    }

    public function test_city_grant_notification_renders_denied_payload(): void
    {
        $request = new CityGrantRequest(['city_number' => 7]);

        $payload = (new CityGrantNotification(1, $request, 'denied'))->toPNW(new \stdClass);

        $this->assertSame('City Grant Denied', $payload['subject']);
        $this->assertStringContainsString('City #7', $payload['message']);
    }

    public function test_rebuilding_notification_renders_approved_amount_and_account_name(): void
    {
        $request = new RebuildingRequest(['approved_amount' => 125000]);
        $account = new Account;
        $account->name = 'Primary';
        $request->setRelation('account', $account);

        $payload = (new RebuildingNotification(1, $request, 'approved'))->toPNW(new \stdClass);

        $this->assertSame('Rebuilding Approved', $payload['subject']);
        $this->assertStringContainsString('$125,000', $payload['message']);
        $this->assertStringContainsString('Primary', $payload['message']);
    }

    public function test_war_aid_notification_renders_denied_payload(): void
    {
        $payload = (new WarAidNotification(1, new WarAidRequest, 'denied'))->toPNW(new \stdClass);

        $this->assertSame('War Aid Denied', $payload['subject']);
        $this->assertStringContainsString('denied', strtolower($payload['message']));
    }

    public function test_loan_notification_renders_payment_success_payload(): void
    {
        $loan = new Loan([
            'amount' => 500,
            'remaining_balance' => 200,
            'next_due_date' => now()->addWeek(),
        ]);

        $payload = (new LoanNotification(1, $loan, 'payment_success', 75))->toPNW(new \stdClass);

        $this->assertSame('Loan Payment Successful!', $payload['subject']);
        $this->assertStringContainsString('$75.00', $payload['message']);
        $this->assertStringContainsString('$200.00', $payload['message']);
    }

    public function test_deposit_notifications_render_created_and_completed_payloads(): void
    {
        $account = new Account;
        $account->name = 'Primary';
        $request = new DepositRequest(['deposit_code' => 'CODE1234']);
        $request->setRelation('account', $account);

        $createdPayload = (new DepositCreated(1, $request))->toPNW(new \stdClass);
        $completedPayload = (new DepositCompletedNotification(1, 'Primary', ['money' => 1000, 'food' => 50]))
            ->toPNW(new \stdClass);

        $this->assertSame('Deposit Request Created', $createdPayload['subject']);
        $this->assertStringContainsString('CODE1234', $createdPayload['message']);
        $this->assertSame('Deposit Confirmed', $completedPayload['subject']);
        $this->assertStringContainsString('Money: $1,000.00', $completedPayload['message']);
        $this->assertStringContainsString('Food: 50.00', $completedPayload['message']);
    }
}
