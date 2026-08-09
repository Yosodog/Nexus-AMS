<?php

namespace Tests\Feature\Models;

use App\Enums\LoanStatus;
use App\Http\Controllers\API\Discord\StaffController;
use App\Http\Controllers\API\Discord\WorkflowController;
use App\Models\Account;
use App\Models\Loan;
use App\Models\Nation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use ReflectionMethod;
use Tests\TestCase;

class LoanStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() === 'sqlite') {
            DB::statement('PRAGMA ignore_check_constraints = ON');
        }
    }

    protected function tearDown(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('PRAGMA ignore_check_constraints = OFF');
        }

        parent::tearDown();
    }

    public function test_unsaved_loan_uses_the_database_status_default(): void
    {
        $loan = new Loan;

        $this->assertSame(LoanStatus::Pending->value, $loan->getAttributes()['status']);
        $this->assertSame(LoanStatus::Pending, $loan->status);
        $this->assertTrue($loan->isPending());
        $this->assertSame(LoanStatus::Pending->value, $loan->toArray()['status']);
    }

    public function test_explicit_null_status_assignment_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Loan status values must be strings or LoanStatus cases.');

        new Loan(['status' => null]);
    }

    public function test_all_known_statuses_round_trip_and_serialize_as_backed_strings(): void
    {
        [$nation, $account] = $this->createAccountOwner();

        foreach (LoanStatus::cases() as $status) {
            $loan = Loan::query()->create([
                'nation_id' => $nation->id,
                'account_id' => $account->id,
                'amount' => 1000,
                'remaining_balance' => 1000,
                'term_weeks' => 4,
                'status' => $status,
                'pending_key' => $status === LoanStatus::Pending ? 1 : null,
            ]);

            $freshLoan = $loan->fresh();

            $this->assertSame($status->value, DB::table('loans')->where('id', $loan->id)->value('status'));
            $this->assertSame($status, $freshLoan->status);
            $this->assertSame($status->value, $freshLoan->statusValue());
            $this->assertSame($status->value, $freshLoan->toArray()['status']);
            $this->assertSame($status->value, json_decode($freshLoan->toJson(), true, flags: JSON_THROW_ON_ERROR)['status']);
        }
    }

    public function test_behavior_and_transition_matrix_is_exhaustive(): void
    {
        $expectations = [
            LoanStatus::Pending->value => [true, false, false, false, [LoanStatus::Approved, LoanStatus::Denied]],
            LoanStatus::Approved->value => [false, true, true, false, [LoanStatus::Missed, LoanStatus::Paid]],
            LoanStatus::Denied->value => [false, false, false, true, []],
            LoanStatus::Paid->value => [false, false, false, true, []],
            LoanStatus::Missed->value => [false, true, true, false, [LoanStatus::Approved, LoanStatus::Paid]],
        ];

        $this->assertSame(array_keys($expectations), LoanStatus::values());
        $this->assertSame(['approved', 'missed'], LoanStatus::activeValues());
        $this->assertSame(['missed', 'past_due'], LoanStatus::attentionValues());

        foreach (LoanStatus::cases() as $status) {
            [$pending, $active, $repayable, $terminal, $allowedTransitions] = $expectations[$status->value];

            $this->assertSame($pending, $status->isPending(), $status->value);
            $this->assertSame($active, $status->isActive(), $status->value);
            $this->assertSame($repayable, $status->isRepayable(), $status->value);
            $this->assertSame($terminal, $status->isTerminal(), $status->value);

            foreach (LoanStatus::cases() as $nextStatus) {
                $this->assertSame(
                    in_array($nextStatus, $allowedTransitions, true),
                    $status->canTransitionTo($nextStatus),
                    "{$status->value} -> {$nextStatus->value}",
                );
            }
        }
    }

    public function test_presentation_mapping_is_exhaustive_and_unknown_safe(): void
    {
        $expected = [
            LoanStatus::Pending->value => ['Pending', 'pending', 'clock'],
            LoanStatus::Approved->value => ['Approved', 'active', 'bolt'],
            LoanStatus::Denied->value => ['Denied', 'failure', 'x-circle'],
            LoanStatus::Paid->value => ['Paid', 'success', 'check-circle'],
            LoanStatus::Missed->value => ['Missed', 'warning', 'exclamation-triangle'],
        ];

        foreach (LoanStatus::cases() as $status) {
            $presentation = $status->presentation();

            $this->assertSame($expected[$status->value][0], $presentation['label']);
            $this->assertSame($expected[$status->value][1], $presentation['intent']);
            $this->assertSame($expected[$status->value][2], $presentation['icon']);
            $this->assertNotSame('', $presentation['explanation']);
            $this->assertSame($presentation, LoanStatus::presentationFor($status->value));
        }

        $this->assertSame([
            'label' => 'Unknown',
            'intent' => 'neutral',
            'icon' => 'minus-circle',
            'explanation' => 'The loan status is unavailable. Contact staff if this looks wrong.',
        ], LoanStatus::presentationFor('legacy_review'));
        $this->assertSame('neutral', LoanStatus::presentationFor('past_due')['intent']);
    }

    public function test_string_and_enum_assignments_keep_api_compatibility(): void
    {
        $loan = new Loan(['status' => LoanStatus::Approved->value]);

        $this->assertSame(LoanStatus::Approved, $loan->status);
        $this->assertSame(LoanStatus::Approved->value, $loan->getAttributes()['status']);
        $this->assertSame(LoanStatus::Approved->value, $loan->toArray()['status']);

        $loan->status = LoanStatus::Paid;

        $this->assertSame(LoanStatus::Paid, $loan->status);
        $this->assertSame(LoanStatus::Paid->value, $loan->getAttributes()['status']);
        $this->assertSame(
            LoanStatus::Paid->value,
            json_decode(response()->json(['loan' => $loan])->getContent(), true, flags: JSON_THROW_ON_ERROR)['loan']['status'],
        );
    }

    public function test_legacy_unknown_database_value_hydrates_and_serializes_without_loss(): void
    {
        $loan = (new Loan)->newFromBuilder([
            'id' => 987,
            'status' => 'legacy_review',
            'amount' => '1000.00',
        ]);

        $this->assertSame('legacy_review', $loan->status);
        $this->assertSame('legacy_review', $loan->statusValue());
        $this->assertFalse($loan->isPending());
        $this->assertFalse($loan->isApproved());
        $this->assertFalse($loan->isRepayable());
        $this->assertSame('neutral', $loan->statusPresentation()['intent']);
        $this->assertSame('legacy_review', $loan->toArray()['status']);
        $this->assertSame(
            'legacy_review',
            json_decode(response()->json(['loan' => $loan])->getContent(), true, flags: JSON_THROW_ON_ERROR)['loan']['status'],
        );

        $loan->amount = 1250;

        $this->assertSame('legacy_review', $loan->getAttributes()['status']);
    }

    public function test_non_string_non_enum_assignments_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Loan(['status' => 123]);
    }

    public function test_unknown_string_assignments_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown loan status [legacy_review] cannot be assigned.');

        new Loan(['status' => 'legacy_review']);
    }

    public function test_discord_attention_filters_preserve_the_legacy_scalar_contract(): void
    {
        $filters = [
            [WorkflowController::class, 'applyRequestStatus'],
            [StaffController::class, 'applyQueueStatus'],
        ];

        foreach ($filters as [$controllerClass, $methodName]) {
            $query = Loan::query();
            $method = new ReflectionMethod($controllerClass, $methodName);
            $method->invoke(app($controllerClass), $query, 'loan', 'needs-attention');

            $this->assertSame(LoanStatus::attentionValues(), $query->getBindings(), $controllerClass);
        }
    }

    /** @return array{Nation, Account} */
    private function createAccountOwner(): array
    {
        $nation = Nation::factory()->create();
        $account = new Account;
        $account->nation_id = $nation->id;
        $account->name = 'Loan status test account';
        $account->save();

        return [$nation, $account];
    }
}
