<?php

namespace Tests\Unit\Milcom;

use App\Domain\Milcom\CounterTeamSelector;
use App\Domain\Milcom\PairAssessment;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CounterTeamSelectorTest extends TestCase
{
    private CounterTeamSelector $selector;

    protected function setUp(): void
    {
        parent::setUp();

        $this->selector = new CounterTeamSelector;
    }

    #[Test]
    public function tied_inputs_produce_the_same_best_trio_regardless_of_input_order(): void
    {
        $assessments = [
            $this->assessment(4, 80.0, 70.0),
            $this->assessment(2, 80.0, 70.0),
            $this->assessment(3, 80.0, 70.0),
            $this->assessment(1, 80.0, 70.0),
        ];

        $forward = $this->selector->select($assessments);
        $reversed = $this->selector->select(array_reverse($assessments));

        $this->assertSame($forward, $reversed);
        $this->assertSame([1, 2, 3], $forward['recommended']['nation_ids']);
        $this->assertFalse($forward['recommended']['partial']);
    }

    #[Test]
    public function weakest_member_emphasis_rejects_a_fragile_specialist_trio(): void
    {
        $result = $this->selector->select([
            $this->assessment(1, 90.0, 0.0),
            $this->assessment(2, 90.0, 0.0),
            $this->assessment(3, 90.0, 0.0),
            $this->assessment(4, 50.0, 100.0),
        ]);

        $this->assertSame([1, 2, 3], $result['recommended']['nation_ids']);
        $this->assertSame(72.0, $result['recommended']['score']);
        $this->assertSame([1, 2, 4], $result['alternatives'][0]['nation_ids']);
        $this->assertSame(68.0, $result['alternatives'][0]['score']);
    }

    #[Test]
    public function selector_persists_three_distinct_deterministic_alternatives(): void
    {
        $result = $this->selector->select([
            $this->assessment(5, 72.0, 75.0),
            $this->assessment(1, 95.0, 65.0),
            $this->assessment(4, 78.0, 95.0),
            $this->assessment(2, 90.0, 80.0),
            $this->assessment(3, 84.0, 85.0),
        ]);

        $this->assertCount(3, $result['alternatives']);

        foreach ($result['alternatives'] as $alternative) {
            $this->assertNotSame($result['recommended']['nation_ids'], $alternative['nation_ids']);
            $sorted = $alternative['nation_ids'];
            sort($sorted);
            $this->assertSame($sorted, $alternative['nation_ids']);
            $this->assertFalse($alternative['partial']);
        }

        $this->assertSame(
            $result,
            $this->selector->select(array_reverse([
                $this->assessment(5, 72.0, 75.0),
                $this->assessment(1, 95.0, 65.0),
                $this->assessment(4, 78.0, 95.0),
                $this->assessment(2, 90.0, 80.0),
                $this->assessment(3, 84.0, 85.0),
            ])),
        );
    }

    #[Test]
    public function empty_one_member_and_two_member_pools_return_explicit_partial_results(): void
    {
        $empty = $this->selector->select([]);
        $single = $this->selector->select([$this->assessment(1, 80.0, 70.0)]);
        $pair = $this->selector->select([
            $this->assessment(2, 75.0, 60.0),
            $this->assessment(1, 80.0, 70.0),
        ]);

        $this->assertNull($empty['recommended']);
        $this->assertSame([], $empty['alternatives']);
        $this->assertSame([1], $single['recommended']['nation_ids']);
        $this->assertTrue($single['recommended']['partial']);
        $this->assertSame([], $single['alternatives']);
        $this->assertSame([1, 2], $pair['recommended']['nation_ids']);
        $this->assertTrue($pair['recommended']['partial']);
        $this->assertSame([], $pair['alternatives']);
    }

    private function assessment(int $nationId, float $score, float $coverage): PairAssessment
    {
        return new PairAssessment(
            friendlyNationId: $nationId,
            targetNationId: 999,
            score: $score,
            confidence: 100.0,
            factors: [
                'air' => $coverage,
                'ground' => $coverage,
                'naval' => $coverage,
                'readiness' => 100.0,
                'tactical_fit' => 100.0,
                'activity' => 100.0,
            ],
            warnings: [],
            explanation: [],
        );
    }
}
