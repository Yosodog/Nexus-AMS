<?php

namespace Tests\Feature\Security;

use App\GraphQL\Models\Nation as GraphQLNation;
use App\Models\Nation;
use App\Rules\InAllianceAndMember;
use App\Services\AllianceMembershipService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class RegistrationMembershipValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_stale_local_membership_cannot_bypass_live_registration_check(): void
    {
        Nation::factory()->create([
            'id' => 701001,
            'alliance_id' => 777,
            'alliance_position' => 'MEMBER',
        ]);
        $membershipService = $this->membershipService();
        $rule = $this->ruleReturning($membershipService, $this->graphNation(701001, 999, 'MEMBER'));
        $failures = [];

        $rule->validate('nation_id', 701001, function (string $message) use (&$failures): void {
            $failures[] = $message;
        });

        $this->assertSame(
            ['You are either not in the alliance or you are still an applicant.'],
            $failures,
        );
        $this->assertSame(999, Nation::query()->findOrFail(701001)->alliance_id);
    }

    public function test_live_member_is_accepted_even_when_local_snapshot_is_stale(): void
    {
        Nation::factory()->create([
            'id' => 701002,
            'alliance_id' => 999,
            'alliance_position' => 'NOALLIANCE',
        ]);
        $membershipService = $this->membershipService();
        $rule = $this->ruleReturning($membershipService, $this->graphNation(701002, 777, 'MEMBER'));
        $failures = [];

        $rule->validate('nation_id', 701002, function (string $message) use (&$failures): void {
            $failures[] = $message;
        });

        $this->assertSame([], $failures);
        $this->assertSame(777, Nation::query()->findOrFail(701002)->alliance_id);
    }

    public function test_live_lookup_errors_fail_closed(): void
    {
        Nation::factory()->create([
            'id' => 701003,
            'alliance_id' => 777,
            'alliance_position' => 'MEMBER',
        ]);
        $membershipService = $this->membershipService();
        $rule = $this->ruleThrowing($membershipService);
        $failures = [];

        $rule->validate('nation_id', 701003, function (string $message) use (&$failures): void {
            $failures[] = $message;
        });

        $this->assertSame(
            ['Unable to verify nation membership right now. Please try again.'],
            $failures,
        );
    }

    private function membershipService(): AllianceMembershipService
    {
        $membershipService = $this->createMock(AllianceMembershipService::class);
        $membershipService->method('contains')
            ->willReturnCallback(fn (?int $allianceId): bool => $allianceId === 777);

        return $membershipService;
    }

    private function graphNation(int $nationId, ?int $allianceId, string $position): GraphQLNation
    {
        $nation = new GraphQLNation;
        $nation->buildWithJSON((object) [
            'id' => $nationId,
            'alliance_id' => $allianceId,
            'alliance_position' => $position,
        ]);

        return $nation;
    }

    private function ruleReturning(
        AllianceMembershipService $membershipService,
        GraphQLNation $nation,
    ): InAllianceAndMember {
        return new class($membershipService, $nation) extends InAllianceAndMember
        {
            public function __construct(
                AllianceMembershipService $membershipService,
                private readonly GraphQLNation $nation,
            ) {
                parent::__construct($membershipService);
            }

            protected function fetchLiveNation(int $nationId): GraphQLNation
            {
                return $this->nation;
            }
        };
    }

    private function ruleThrowing(AllianceMembershipService $membershipService): InAllianceAndMember
    {
        return new class($membershipService) extends InAllianceAndMember
        {
            protected function fetchLiveNation(int $nationId): GraphQLNation
            {
                throw new RuntimeException('P&W unavailable');
            }
        };
    }
}
