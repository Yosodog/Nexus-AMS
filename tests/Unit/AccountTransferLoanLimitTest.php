<?php

namespace Tests\Unit;

use Tests\UnitTestCase;

class AccountTransferLoanLimitTest extends UnitTestCase
{
    public function test_transfer_component_preserves_the_loan_limit_and_input_listener(): void
    {
        $component = file_get_contents(
            dirname(__DIR__, 2).'/resources/views/accounts/components/transfer.blade.php',
        ) ?: '';

        $this->assertStringContainsString('Math.min(amount, loanRemainingBalance)', $component);
        $this->assertStringContainsString("input.dataset.validationBound = 'true'", $component);
        $this->assertStringNotContainsString('cloneNode', $component);
        $this->assertStringNotContainsString('replaceChild', $component);
    }
}
