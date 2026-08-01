<?php

namespace Tests\Unit\Services;

use App\GraphQL\Models\Nation as GraphQlNation;
use App\Models\Nation;
use App\Services\AllianceMembershipService;
use App\Services\AuthoritativeNationMembershipService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\FeatureTestCase;

class AuthoritativeNationMembershipServiceTest extends FeatureTestCase
{
    use RefreshDatabase;

    public function test_stale_local_member_is_rejected_when_live_state_is_outside_the_alliance(): void
    {
        $nation = Nation::factory()->create([
            'alliance_id' => 777,
            'alliance_position' => 'MEMBER',
        ]);
        $membership = $this->createMock(AllianceMembershipService::class);
        $membership->expects($this->once())->method('contains')->with(null)->willReturn(false);
        $service = $this->service($membership, $this->liveNation($nation->id, null, 'NOALLIANCE'));

        try {
            $service->validate($nation->id);
            $this->fail('Expected current membership validation to reject the nation.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                'The recipient is no longer a member of the required alliance.',
                $exception->errors()['alliance'][0],
            );
        }

        $this->assertNull($nation->fresh()->alliance_id);
        $this->assertSame('NOALLIANCE', $nation->fresh()->alliance_position);
    }

    public function test_live_member_is_accepted_and_refreshes_the_local_snapshot(): void
    {
        $nation = Nation::factory()->create([
            'alliance_id' => null,
            'alliance_position' => 'NOALLIANCE',
        ]);
        $membership = $this->createMock(AllianceMembershipService::class);
        $membership->expects($this->once())->method('contains')->with(777)->willReturn(true);
        $service = $this->service($membership, $this->liveNation($nation->id, 777, 'MEMBER'));

        $service->validate($nation->id);

        $this->assertSame(777, $nation->fresh()->alliance_id);
        $this->assertSame('MEMBER', $nation->fresh()->alliance_position);
    }

    public function test_lookup_failure_is_rejected_without_trusting_the_local_snapshot(): void
    {
        $nation = Nation::factory()->create([
            'alliance_id' => 777,
            'alliance_position' => 'MEMBER',
        ]);
        $membership = $this->createMock(AllianceMembershipService::class);
        $membership->expects($this->never())->method('contains');
        $service = $this->service($membership, exception: new RuntimeException('P&W unavailable'));

        try {
            $service->validate($nation->id);
            $this->fail('Expected current membership validation to fail closed.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                'Unable to verify current alliance membership. Please try again.',
                $exception->errors()['alliance'][0],
            );
        }
    }

    private function liveNation(int $nationId, ?int $allianceId, string $position): GraphQlNation
    {
        $nation = new GraphQlNation;
        $nation->buildWithJSON((object) [
            'id' => $nationId,
            'alliance_id' => $allianceId,
            'alliance_position' => $position,
        ]);

        return $nation;
    }

    private function service(
        AllianceMembershipService $membership,
        ?GraphQlNation $nation = null,
        ?RuntimeException $exception = null,
    ): AuthoritativeNationMembershipService {
        return new class($membership, $nation, $exception) extends AuthoritativeNationMembershipService
        {
            public function __construct(
                AllianceMembershipService $membership,
                private readonly ?GraphQlNation $nation,
                private readonly ?RuntimeException $exception,
            ) {
                parent::__construct($membership);
            }

            protected function fetchNation(int $nationId): GraphQlNation
            {
                if ($this->exception !== null) {
                    throw $this->exception;
                }

                return $this->nation;
            }
        };
    }
}
