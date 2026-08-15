<?php

namespace Tests\Feature\Migrations;

use App\Domain\Milcom\Enums\ObjectiveStatus;
use App\Domain\Milcom\Enums\OperationStatus;
use App\Domain\Milcom\Enums\OperationType;
use App\Models\Nation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Milcom\Concerns\BuildsMilcomFixtures;
use Tests\TestCase;

class MilcomCounterDeadlineMigrationTest extends TestCase
{
    use BuildsMilcomFixtures;
    use RefreshDatabase;

    public function test_backfill_clears_review_deadlines_marks_dispatches_overdue_and_leaves_other_work_untouched(): void
    {
        $migration = $this->migration();
        $migration->down();
        $past = now()->subHour()->startOfSecond();

        $reviewOperation = $this->createMilcomOperation([
            'type' => OperationType::Counter,
            'status' => OperationStatus::Review,
            'deadline_at' => $past,
        ]);
        $reviewObjective = $this->createMilcomObjective(
            $reviewOperation,
            Nation::factory()->create(),
            [
                'status' => ObjectiveStatus::Review,
                'deadline_at' => $past,
            ],
        );

        $dispatchedOperation = $this->createMilcomOperation([
            'type' => OperationType::Counter,
            'status' => OperationStatus::Active,
            'deadline_at' => $past,
        ]);
        $dispatchedObjective = $this->createMilcomObjective(
            $dispatchedOperation,
            Nation::factory()->create(),
            [
                'status' => ObjectiveStatus::Dispatched,
                'deadline_at' => $past,
            ],
        );

        $terminalOperation = $this->createMilcomOperation([
            'type' => OperationType::Counter,
            'status' => OperationStatus::Completed,
            'deadline_at' => $past,
        ]);
        $terminalObjective = $this->createMilcomObjective(
            $terminalOperation,
            Nation::factory()->create(),
            [
                'status' => ObjectiveStatus::Review,
                'deadline_at' => $past,
            ],
        );

        $planOperation = $this->createMilcomOperation([
            'type' => OperationType::Plan,
            'status' => OperationStatus::Review,
            'deadline_at' => $past,
        ]);
        $planObjective = $this->createMilcomObjective(
            $planOperation,
            Nation::factory()->create(),
            [
                'status' => ObjectiveStatus::Review,
                'deadline_at' => $past,
            ],
        );

        $migration->up();

        $this->assertNull($reviewOperation->fresh()->deadline_at);
        $this->assertNull($reviewObjective->fresh()->deadline_at);
        $this->assertNotNull($dispatchedObjective->fresh()->declaration_overdue_at);
        $this->assertTrue($past->equalTo($dispatchedObjective->fresh()->declaration_overdue_at));
        $this->assertTrue($past->equalTo($terminalOperation->fresh()->deadline_at));
        $this->assertTrue($past->equalTo($terminalObjective->fresh()->deadline_at));
        $this->assertTrue($past->equalTo($planOperation->fresh()->deadline_at));
        $this->assertTrue($past->equalTo($planObjective->fresh()->deadline_at));
        $this->assertDatabaseCount('milcom_operations', 4);
        $this->assertDatabaseCount('milcom_objectives', 4);
    }

    private function migration(): object
    {
        return require database_path(
            'migrations/2026_08_15_180115_add_declaration_overdue_at_to_milcom_objectives_table.php',
        );
    }
}
