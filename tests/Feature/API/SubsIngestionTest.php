<?php

namespace Tests\Feature\API;

use App\Events\WarDeclared;
use App\Jobs\CreateNationJob;
use App\Jobs\CreateWarAttackJob;
use App\Jobs\DeleteNationAccountJob;
use App\Jobs\RefreshNationProfitabilitySnapshotJob;
use App\Jobs\UpdateAllianceJob;
use App\Jobs\UpdateCityJob;
use App\Jobs\UpdateNationJob;
use App\Jobs\UpdateWarJob;
use App\Jobs\UpsertNationAccountJob;
use App\Models\Alliance;
use App\Models\City;
use App\Models\Nation;
use App\Models\NationAccount;
use App\Models\War;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\FeatureTestCase;

class SubsIngestionTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureIsolatedTestDatabase();
        Schema::dropAllTables();
        $this->createTables();
        config()->set('services.nexus_api_token', 'testing-nexus-token');
    }

    public function test_subscription_endpoints_require_a_valid_bearer_token(): void
    {
        $this->postJson('/api/v1/subs/nation/update', ['id' => 1])
            ->assertUnauthorized()
            ->assertJson(['error' => 'Unauthorized']);
    }

    public function test_nation_create_and_update_queue_jobs(): void
    {
        Queue::fake();

        $payload = [[
            'id' => 101,
            'leader_name' => 'Tester',
            'nation_name' => 'Arcadia',
        ]];

        $this->postSubsJson('/api/v1/subs/nation/create', $payload)
            ->assertOk()
            ->assertJson(['message' => 'Nation creation(s) queued for processing']);

        $this->postSubsJson('/api/v1/subs/nation/update', $payload)
            ->assertOk()
            ->assertJson(['message' => 'Nation update(s) queued for processing']);

        Queue::assertPushed(CreateNationJob::class, fn (CreateNationJob $job) => $job->nationsData === $payload);
        Queue::assertPushed(UpdateNationJob::class, fn (UpdateNationJob $job) => $job->nationsData === $payload);
    }

    public function test_alliance_create_uses_a_simulated_pw_response_and_persists_the_alliance(): void
    {
        Http::fake([
            'https://pw.test/graphql*' => Http::response([
                'data' => [
                    'alliances' => [
                        'data' => [[
                            'id' => 9988,
                            'name' => 'Enemy Coalition',
                            'acronym' => 'EC',
                            'score' => 12345.67,
                            'color' => 'blue',
                            'average_score' => 123.45,
                            'accept_members' => true,
                            'flag' => 'https://example.com/flag.png',
                            'forum_link' => 'https://example.com/forum',
                            'discord_link' => 'https://example.com/discord',
                            'wiki_link' => 'https://example.com/wiki',
                            'money' => null,
                            'coal' => null,
                            'oil' => null,
                            'uranium' => null,
                            'iron' => null,
                            'bauxite' => null,
                            'lead' => null,
                            'gasoline' => null,
                            'munitions' => null,
                            'steel' => null,
                            'aluminum' => null,
                            'food' => null,
                            'rank' => 12,
                        ]],
                    ],
                ],
            ], 200),
        ]);

        $this->postSubsJson('/api/v1/subs/alliance/create', ['id' => 9988])
            ->assertOk()
            ->assertJson(['message' => 'Alliance created successfully']);

        $this->assertDatabaseHas('alliances', [
            'id' => 9988,
            'name' => 'Enemy Coalition',
            'acronym' => 'EC',
        ]);
    }

    public function test_alliance_update_queues_the_job(): void
    {
        Queue::fake();

        $payload = [['id' => 77, 'name' => 'Updated Alliance']];

        $this->postSubsJson('/api/v1/subs/alliance/update', $payload)
            ->assertOk()
            ->assertJson(['message' => 'Alliance update(s) queued for processing']);

        Queue::assertPushed(UpdateAllianceJob::class, fn (UpdateAllianceJob $job) => $job->alliancesData === $payload);
    }

    public function test_alliance_delete_removes_the_record(): void
    {
        Alliance::factory()->create([
            'id' => 77,
            'name' => 'Delete Me',
            'acronym' => 'DEL',
        ]);

        $this->postSubsJson('/api/v1/subs/alliance/delete', ['id' => 77])
            ->assertOk()
            ->assertJson(['message' => 'Alliance deleted successfully']);

        $this->assertDatabaseMissing('alliances', ['id' => 77]);
    }

    public function test_city_create_and_update_queue_jobs(): void
    {
        Queue::fake();

        $payload = [['id' => 501, 'nation_id' => 2026, 'name' => 'Capital']];

        $this->postSubsJson('/api/v1/subs/city/create', $payload)->assertOk();
        $this->postSubsJson('/api/v1/subs/city/update', $payload)->assertOk();

        Queue::assertPushed(UpdateCityJob::class, 2);
    }

    public function test_city_delete_removes_the_city_and_refreshes_profitability_for_eligible_nations(): void
    {
        Queue::fake();

        cache()->forever('alliances:membership:ids', [1]);

        Nation::factory()->create([
            'id' => 2026,
            'alliance_id' => 1,
            'alliance_position' => 'MEMBER',
            'vacation_mode_turns' => 0,
        ]);

        City::query()->create([
            'id' => 501,
            'nation_id' => 2026,
            'name' => 'Capital',
            'date' => now()->toDateString(),
            'infrastructure' => 500,
            'land' => 500,
            'powered' => true,
            'oil_power' => 0,
            'wind_power' => 0,
            'coal_power' => 0,
            'nuclear_power' => 0,
            'coal_mine' => 0,
            'oil_well' => 0,
            'uranium_mine' => 0,
            'barracks' => 0,
            'farm' => 0,
            'police_station' => 0,
            'hospital' => 0,
            'recycling_center' => 0,
            'subway' => 0,
            'supermarket' => 0,
            'bank' => 0,
            'shopping_mall' => 0,
            'stadium' => 0,
            'lead_mine' => 0,
            'iron_mine' => 0,
            'bauxite_mine' => 0,
            'oil_refinery' => 0,
            'aluminum_refinery' => 0,
            'steel_mill' => 0,
            'munitions_factory' => 0,
            'factory' => 0,
            'hangar' => 0,
            'drydock' => 0,
        ]);

        $this->postSubsJson('/api/v1/subs/city/delete', ['id' => 501])
            ->assertOk()
            ->assertJson(['message' => 'Alliance deleted successfully']);

        $this->assertSoftDeleted('cities', ['id' => 501]);
        Queue::assertPushed(RefreshNationProfitabilitySnapshotJob::class);
    }

    public function test_war_create_persists_alliance_wars_and_dispatches_the_event_only_once(): void
    {
        Event::fake();

        cache()->forever('alliances:membership:ids', [321]);

        $payload = [
            'id' => 901,
            'date' => '2026-03-01 12:00:00',
            'reason' => 'Counter',
            'war_type' => 'ORDINARY',
            'turns_left' => 12,
            'att_id' => 1001,
            'att_alliance_id' => 321,
            'att_alliance_position' => 'MEMBER',
            'def_id' => 2002,
            'def_alliance_id' => 999,
            'def_alliance_position' => 'MEMBER',
        ];

        $this->postSubsJson('/api/v1/subs/war/create', $payload)
            ->assertOk()
            ->assertJson(['message' => 'War created successfully']);

        $this->postSubsJson('/api/v1/subs/war/create', $payload)->assertOk();

        $this->assertDatabaseHas('wars', ['id' => 901, 'att_alliance_id' => 321]);
        Event::assertDispatchedTimes(WarDeclared::class, 1);
    }

    public function test_war_update_and_attack_endpoints_queue_jobs(): void
    {
        Queue::fake();

        $warPayload = [['id' => 901, 'att_alliance_id' => 321, 'def_alliance_id' => 999]];
        $attackPayload = [[
            'id' => 7001,
            'att_id' => 1001,
            'def_id' => 2002,
            'war_id' => 901,
            'type' => 'GROUND',
        ]];

        $this->postSubsJson('/api/v1/subs/war/update', $warPayload)->assertOk();
        $this->postSubsJson('/api/v1/subs/warattack/create', $attackPayload)->assertOk();

        Queue::assertPushed(UpdateWarJob::class, fn (UpdateWarJob $job) => $job->warsData === $warPayload);
        Queue::assertPushed(CreateWarAttackJob::class, fn (CreateWarAttackJob $job) => $job->warAttacks === $attackPayload);
    }

    public function test_war_delete_removes_existing_wars(): void
    {
        War::query()->create([
            'id' => 901,
            'date' => now(),
            'reason' => 'Counter',
            'war_type' => 'ORDINARY',
            'turns_left' => 12,
            'att_id' => 1001,
            'att_alliance_id' => 321,
            'att_alliance_position' => 'MEMBER',
            'def_id' => 2002,
            'def_alliance_id' => 999,
            'def_alliance_position' => 'MEMBER',
        ]);

        $this->postSubsJson('/api/v1/subs/war/delete', ['id' => 901])
            ->assertOk()
            ->assertJson(['message' => 'War(s) deleted successfully']);

        $this->assertDatabaseMissing('wars', ['id' => 901]);
    }

    public function test_account_create_and_update_queue_upserts(): void
    {
        Queue::fake();

        $payload = [['id' => 2026, 'credits' => 9, 'discord_id' => '12345']];

        $this->postSubsJson('/api/v1/subs/account/create', $payload)->assertOk();
        $this->postSubsJson('/api/v1/subs/account/update', $payload)->assertOk();

        Queue::assertPushed(UpsertNationAccountJob::class, 2);
    }

    public function test_account_delete_queues_the_delete_job(): void
    {
        Queue::fake();

        $payload = [['id' => 2026]];

        $this->postSubsJson('/api/v1/subs/account/delete', $payload)->assertOk();

        Queue::assertPushed(DeleteNationAccountJob::class, fn (DeleteNationAccountJob $job) => $job->accounts === $payload);
    }

    public function test_nation_delete_soft_deletes_the_record(): void
    {
        Nation::factory()->create(['id' => 2026]);

        $this->postSubsJson('/api/v1/subs/nation/delete', ['id' => 2026])
            ->assertOk()
            ->assertJson(['message' => 'Nation deleted successfully']);

        $this->assertSoftDeleted('nations', ['id' => 2026]);
    }

    public function test_nation_account_model_ignores_events_for_unknown_nations(): void
    {
        NationAccount::upsertFromEvent([
            'id' => 9999,
            'credits' => 5,
            'discord_id' => '123',
        ]);

        $this->assertDatabaseCount('nation_accounts', 0);
    }

    protected function postSubsJson(string $uri, array $payload)
    {
        return $this->withHeader('Authorization', 'Bearer testing-nexus-token')
            ->postJson($uri, $payload);
    }

    private function createTables(): void
    {
        Schema::create('alliances', function ($table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('acronym', 10)->nullable();
            $table->float('score')->default(0);
            $table->string('color', 20)->nullable();
            $table->float('average_score')->default(0);
            $table->boolean('accept_members')->default(true);
            $table->string('flag')->nullable();
            $table->string('forum_link')->nullable();
            $table->string('discord_link')->nullable();
            $table->string('wiki_link')->nullable();
            $table->unsignedSmallInteger('rank')->default(0);
            $table->timestamps();
        });

        Schema::create('nations', function ($table): void {
            $table->id();
            $table->unsignedBigInteger('alliance_id')->nullable();
            $table->string('alliance_position')->default('MEMBER');
            $table->unsignedInteger('alliance_position_id')->default(1);
            $table->string('nation_name');
            $table->string('leader_name');
            $table->string('continent')->default('NA');
            $table->string('war_policy')->default('ATTRITION');
            $table->unsignedSmallInteger('war_policy_turns')->default(0);
            $table->string('domestic_policy')->default('MANIFEST_DESTINY');
            $table->unsignedSmallInteger('domestic_policy_turns')->default(0);
            $table->string('color')->default('blue');
            $table->unsignedSmallInteger('num_cities')->default(0);
            $table->float('score')->default(0);
            $table->tinyInteger('update_tz')->nullable();
            $table->unsignedInteger('population')->default(0);
            $table->string('flag')->nullable();
            $table->unsignedSmallInteger('vacation_mode_turns')->default(0);
            $table->unsignedSmallInteger('beige_turns')->default(0);
            $table->boolean('espionage_available')->default(false);
            $table->string('discord')->nullable();
            $table->string('discord_id')->nullable();
            $table->unsignedSmallInteger('turns_since_last_city')->default(0);
            $table->unsignedSmallInteger('turns_since_last_project')->default(0);
            $table->unsignedTinyInteger('projects')->default(0);
            $table->string('project_bits')->default('0');
            $table->unsignedInteger('wars_won')->default(0);
            $table->unsignedInteger('wars_lost')->default(0);
            $table->unsignedInteger('tax_id')->nullable();
            $table->unsignedInteger('alliance_seniority')->default(0);
            $table->float('gross_national_income')->default(0);
            $table->float('gross_domestic_product')->default(0);
            $table->boolean('vip')->default(false);
            $table->unsignedSmallInteger('commendations')->default(0);
            $table->unsignedSmallInteger('denouncements')->default(0);
            $table->unsignedInteger('offensive_wars_count')->default(0);
            $table->unsignedInteger('defensive_wars_count')->default(0);
            $table->float('money_looted')->default(0);
            $table->float('total_infrastructure_destroyed')->default(0);
            $table->float('total_infrastructure_lost')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('nation_profitability_snapshots', function ($table): void {
            $table->id();
            $table->unsignedBigInteger('nation_id');
            $table->timestamps();
        });

        Schema::create('cities', function ($table): void {
            $table->id();
            $table->unsignedBigInteger('nation_id');
            $table->string('name');
            $table->date('date');
            $table->float('infrastructure');
            $table->float('land');
            $table->boolean('powered');
            $table->integer('oil_power');
            $table->integer('wind_power');
            $table->integer('coal_power');
            $table->integer('nuclear_power');
            $table->integer('coal_mine');
            $table->integer('oil_well');
            $table->integer('uranium_mine');
            $table->integer('barracks');
            $table->integer('farm');
            $table->integer('police_station');
            $table->integer('hospital');
            $table->integer('recycling_center');
            $table->integer('subway');
            $table->integer('supermarket');
            $table->integer('bank');
            $table->integer('shopping_mall');
            $table->integer('stadium');
            $table->integer('lead_mine');
            $table->integer('iron_mine');
            $table->integer('bauxite_mine');
            $table->integer('oil_refinery');
            $table->integer('aluminum_refinery');
            $table->integer('steel_mill');
            $table->integer('munitions_factory');
            $table->integer('factory');
            $table->integer('hangar');
            $table->integer('drydock');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('wars', function ($table): void {
            $table->id();
            $table->timestamp('date')->nullable();
            $table->timestamp('end_date')->nullable();
            $table->string('reason');
            $table->string('war_type')->default('ORDINARY');
            $table->unsignedBigInteger('ground_control')->nullable();
            $table->unsignedBigInteger('air_superiority')->nullable();
            $table->unsignedBigInteger('naval_blockade')->nullable();
            $table->unsignedBigInteger('winner_id')->nullable();
            $table->unsignedInteger('turns_left')->default(0);
            $table->unsignedBigInteger('att_id');
            $table->unsignedBigInteger('att_alliance_id')->nullable();
            $table->string('att_alliance_position')->default('NOALLIANCE');
            $table->unsignedBigInteger('def_id');
            $table->unsignedBigInteger('def_alliance_id')->nullable();
            $table->string('def_alliance_position')->default('NOALLIANCE');
            $table->unsignedInteger('att_points')->default(0);
            $table->unsignedInteger('def_points')->default(0);
            $table->boolean('att_peace')->default(false);
            $table->boolean('def_peace')->default(false);
            $table->unsignedInteger('att_resistance')->default(0);
            $table->unsignedInteger('def_resistance')->default(0);
            $table->boolean('att_fortify')->default(false);
            $table->boolean('def_fortify')->default(false);
            $table->float('att_gas_used')->default(0);
            $table->float('def_gas_used')->default(0);
            $table->float('att_mun_used')->default(0);
            $table->float('def_mun_used')->default(0);
            $table->float('att_alum_used')->default(0);
            $table->float('def_alum_used')->default(0);
            $table->float('att_steel_used')->default(0);
            $table->float('def_steel_used')->default(0);
            $table->float('att_infra_destroyed')->default(0);
            $table->float('def_infra_destroyed')->default(0);
            $table->float('att_money_looted')->default(0);
            $table->float('def_money_looted')->default(0);
            $table->unsignedInteger('def_soldiers_lost')->default(0);
            $table->unsignedInteger('att_soldiers_lost')->default(0);
            $table->unsignedInteger('def_tanks_lost')->default(0);
            $table->unsignedInteger('att_tanks_lost')->default(0);
            $table->unsignedInteger('def_aircraft_lost')->default(0);
            $table->unsignedInteger('att_aircraft_lost')->default(0);
            $table->unsignedInteger('def_ships_lost')->default(0);
            $table->unsignedInteger('att_ships_lost')->default(0);
            $table->unsignedInteger('att_missiles_used')->default(0);
            $table->unsignedInteger('def_missiles_used')->default(0);
            $table->unsignedInteger('att_nukes_used')->default(0);
            $table->unsignedInteger('def_nukes_used')->default(0);
            $table->float('att_infra_destroyed_value')->default(0);
            $table->float('def_infra_destroyed_value')->default(0);
            $table->timestamps();
        });

        Schema::create('nation_accounts', function ($table): void {
            $table->unsignedBigInteger('nation_id')->primary();
            $table->unsignedInteger('credits')->nullable();
            $table->timestamp('last_active')->nullable();
            $table->string('discord_id', 32)->nullable();
            $table->timestamps();
        });
    }
}
