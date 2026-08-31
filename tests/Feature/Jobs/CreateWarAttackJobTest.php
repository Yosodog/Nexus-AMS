<?php

namespace Tests\Feature\Jobs;

use App\Jobs\CreateWarAttackJob;
use App\Models\Nation;
use App\Models\WarAttack;
use App\Services\SubscriptionRecordQuarantine;
use App\Services\World\WorldWriteGuard;
use Illuminate\Support\Facades\Schema;
use Tests\FeatureTestCase;

class CreateWarAttackJobTest extends FeatureTestCase
{
    private string $quarantineFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureIsolatedTestDatabase();
        Schema::dropAllTables();
        $this->createTables();

        $this->quarantineFile = sys_get_temp_dir().'/nexus-war-attack-quarantine-'.bin2hex(random_bytes(6)).'.jsonl';
        config()->set('subscriptions.ingestion.quarantine_file', $this->quarantineFile);
        cache()->forever('alliances:membership:ids', [321]);

        Nation::query()->insert([
            ['id' => 1001, 'alliance_id' => 321],
            ['id' => 2002, 'alliance_id' => 999],
        ]);
    }

    protected function tearDown(): void
    {
        if (is_file($this->quarantineFile)) {
            unlink($this->quarantineFile);
        }

        parent::tearDown();
    }

    public function test_unknown_attack_types_are_quarantined_without_poisoning_valid_records(): void
    {
        $job = new CreateWarAttackJob([
            [
                'id' => 7001,
                'date' => now()->toIso8601String(),
                'att_id' => 1001,
                'def_id' => 2002,
                'war_id' => 901,
                'type' => 'NEW_UPSTREAM_ATTACK',
            ],
            [
                'id' => 7002,
                'date' => now()->toIso8601String(),
                'att_id' => 1001,
                'def_id' => 2002,
                'war_id' => 901,
                'type' => 'GROUND',
            ],
        ]);

        $job->handle(
            app(SubscriptionRecordQuarantine::class),
            app(WorldWriteGuard::class),
        );

        $this->assertDatabaseMissing('war_attacks', ['id' => 7001]);
        $this->assertDatabaseHas('war_attacks', ['id' => 7002, 'type' => 'GROUND']);
        $this->assertFileExists($this->quarantineFile);
        $this->assertStringContainsString('unknown_war_attack_type', file_get_contents($this->quarantineFile));
        $this->assertStringContainsString('NEW_UPSTREAM_ATTACK', file_get_contents($this->quarantineFile));
    }

    public function test_pruning_preserves_the_full_raid_leaderboard_window(): void
    {
        WarAttack::query()->insert([
            $this->warAttackRow(7101, now()->subDays(89)),
            $this->warAttackRow(7102, now()->subDays(91)),
        ]);

        $job = new CreateWarAttackJob([
            $this->warAttackRow(7103, now()),
        ]);

        $job->handle(
            app(SubscriptionRecordQuarantine::class),
            app(WorldWriteGuard::class),
        );

        $this->assertDatabaseHas('war_attacks', ['id' => 7101]);
        $this->assertDatabaseMissing('war_attacks', ['id' => 7102]);
        $this->assertDatabaseHas('war_attacks', ['id' => 7103]);
    }

    /** @return array<string, mixed> */
    private function warAttackRow(int $id, \DateTimeInterface $date): array
    {
        return [
            'id' => $id,
            'date' => $date,
            'att_id' => 1001,
            'def_id' => 2002,
            'war_id' => 901,
            'type' => 'GROUND',
        ];
    }

    private function createTables(): void
    {
        Schema::create('nations', function ($table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('alliance_id')->nullable();
            $table->softDeletes();
        });

        Schema::create('war_attacks', function ($table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->timestamp('date')->nullable();
            $table->unsignedBigInteger('att_id');
            $table->unsignedBigInteger('def_id');
            $table->string('type');
            $table->unsignedBigInteger('war_id');
            $table->json('improvements_destroyed')->nullable();
            $table->json('cities_infra_before')->nullable();
            $table->timestamps();
        });
    }
}
