<?php

namespace Tests\Integration;

use App\Models\Nation;
use App\Models\RaidTargetClaim;
use App\Models\User;
use App\Services\Raids\RaidTargetClaimService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RaidTargetClaimUniquenessTest extends MySqlIntegrationTestCase
{
    private const TARGET = 424242;

    public function test_the_database_allows_only_one_active_claim_per_target(): void
    {
        DB::table('raid_target_claims')->insert($this->row(101));

        $this->expectException(UniqueConstraintViolationException::class);

        DB::table('raid_target_claims')->insert($this->row(102));
    }

    public function test_historical_claims_do_not_block_a_new_active_claim(): void
    {
        DB::table('raid_target_claims')->insert([
            [...$this->row(101), 'status' => RaidTargetClaim::STATUS_RELEASED, 'pending_key' => null],
            [...$this->row(102), 'status' => RaidTargetClaim::STATUS_EXPIRED, 'pending_key' => null],
            [...$this->row(103), 'status' => RaidTargetClaim::STATUS_DECLARED, 'pending_key' => null],
        ]);

        DB::table('raid_target_claims')->insert($this->row(104));

        $this->assertSame(1, RaidTargetClaim::query()->where('status', RaidTargetClaim::STATUS_ACTIVE)->count());
    }

    public function test_a_concurrent_active_claim_surfaces_as_a_validation_error(): void
    {
        $user = User::factory()->verified()->create(['nation_id' => Nation::factory()->create()->id]);
        $competitorInserted = false;

        RaidTargetClaim::creating(function () use (&$competitorInserted): void {
            if (! $competitorInserted) {
                $competitorInserted = true;
                DB::table('raid_target_claims')->insert($this->row(999));
            }
        });

        try {
            app(RaidTargetClaimService::class)->claim(self::TARGET, $user);
            $this->fail('A concurrent active claim was accepted.');
        } catch (ValidationException $exception) {
            $this->assertSame(['Another member already claimed this target.'], $exception->errors()['target_nation_id']);
        }

        $this->assertSame(1, RaidTargetClaim::query()->where('target_nation_id', self::TARGET)->count());
        $this->assertSame(999, (int) RaidTargetClaim::query()->value('nation_id'));
    }

    /**
     * @return array<string, mixed>
     */
    private function row(int $nationId): array
    {
        return [
            'target_nation_id' => self::TARGET,
            'nation_id' => $nationId,
            'status' => RaidTargetClaim::STATUS_ACTIVE,
            'pending_key' => 1,
            'expires_at' => now()->addHour(),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}
