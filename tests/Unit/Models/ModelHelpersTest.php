<?php

namespace Tests\Unit\Models;

use App\Enums\ApplicationStatus;
use App\Models\Application;
use App\Models\Loan;
use App\Services\LoanService;
use Tests\FeatureTestCase;

class ModelHelpersTest extends FeatureTestCase
{
    public function test_application_is_pending_helper_uses_enum_status(): void
    {
        $application = new Application(['status' => ApplicationStatus::Pending]);
        $approved = new Application(['status' => ApplicationStatus::Approved]);

        $this->assertTrue($application->isPending());
        $this->assertFalse($approved->isPending());
    }

    public function test_loan_status_helpers_report_pending_and_approved_states(): void
    {
        $pending = new Loan(['status' => 'pending']);
        $approved = new Loan(['status' => 'approved']);

        $this->assertTrue($pending->isPending());
        $this->assertFalse($pending->isApproved());
        $this->assertTrue($approved->isApproved());
    }

    public function test_loan_get_next_payment_due_delegates_to_loan_service(): void
    {
        $loan = new Loan(['amount' => 100]);
        $service = $this->createMock(LoanService::class);
        $service->expects($this->once())->method('calculateCurrentAmountDue')->with($loan)->willReturn(42.5);
        $this->app->instance(LoanService::class, $service);

        $this->assertSame(42.5, $loan->getNextPaymentDue());
    }
}
