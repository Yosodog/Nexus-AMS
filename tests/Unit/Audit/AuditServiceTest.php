<?php

namespace Tests\Unit\Audit;

use App\Enums\AuditEvaluationStatus;
use App\Enums\AuditPriority;
use App\Enums\AuditTargetType;
use App\Models\AuditResult;
use App\Models\AuditRule;
use App\Models\City;
use App\Models\Nation;
use App\Models\NationSignIn;
use App\Services\AllianceMembershipService;
use App\Services\Audit\AuditService;
use App\Services\PWHelperService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AuditServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureIsolatedTestDatabase();
        Schema::dropAllTables();
        $this->createTables();

        Cache::flush();
        config()->set('services.pw.alliance_id', 1);
        Cache::forever('alliances:membership:ids', [1]);
        $membership = app(AllianceMembershipService::class);
        $membership->clear();
        $membership->refresh();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_opens_updates_and_resolves_findings_with_evidence(): void
    {
        Carbon::setTestNow('2026-08-02 12:00:00');
        $nation = $this->createNation(score: 900);

        $rule = AuditRule::query()->create([
            'name' => 'Score threshold',
            'description' => 'Keep score above the alliance minimum.',
            'target_type' => AuditTargetType::Nation,
            'priority' => AuditPriority::High,
            'definition' => $this->definition('nation.score', 'lt', 1000),
            'revision' => 3,
            'enabled' => true,
        ]);

        app(AuditService::class)->runAllEnabledRules();

        $finding = AuditResult::query()->sole();
        $firstDetectedAt = $finding->first_detected_at->copy();

        $this->assertSame($rule->id, $finding->audit_rule_id);
        $this->assertSame(3, $finding->rule_revision);
        $this->assertSame('nation', $finding->target_type->value);
        $this->assertSame("nation:{$nation->id}", $finding->target_key);
        $this->assertSame(3, $finding->details['rule_revision']);
        $this->assertStringContainsString('Score is less than 1,000 score', $finding->details['summary']);
        $this->assertSame(900.0, (float) $finding->details['evidence'][0]['observed']);
        $this->assertSame(1000, $finding->details['evidence'][0]['expected']);
        $this->assertTrue($finding->details['evidence'][0]['matched']);
        $this->assertTrue($finding->details['evidence'][0]['member_safe']);
        $this->assertDatabaseHas('audit_result_events', [
            'audit_result_id' => $finding->id,
            'event_type' => 'opened',
        ]);

        Carbon::setTestNow('2026-08-02 13:00:00');
        $nation->update(['score' => 800]);

        app(AuditService::class)->runAllEnabledRules();

        $updated = $finding->fresh();
        $this->assertNotNull($updated);
        $this->assertTrue($updated->first_detected_at->equalTo($firstDetectedAt));
        $this->assertTrue($updated->last_evaluated_at->equalTo(now()));
        $this->assertSame(800.0, (float) $updated->details['evidence'][0]['observed']);
        $this->assertSame(1, DB::table('audit_result_events')->where('event_type', 'opened')->count());

        Carbon::setTestNow('2026-08-02 14:00:00');
        $nation->update(['score' => 1500]);

        app(AuditService::class)->runAllEnabledRules();

        $this->assertDatabaseCount('audit_results', 0);
        $this->assertDatabaseHas('audit_result_events', [
            'audit_result_id' => $finding->id,
            'event_type' => 'resolved',
        ]);
    }

    public function test_city_rules_can_match_nation_project_collections(): void
    {
        $nation = $this->createNation(score: 900);
        $nation->update([
            'project_bits' => (string) PWHelperService::PROJECTS['Urban Planning'],
        ]);
        $city = $this->createCity($nation, infrastructure: 500);

        $rule = AuditRule::query()->create([
            'name' => 'Urban Planning owner',
            'target_type' => AuditTargetType::City,
            'priority' => AuditPriority::Medium,
            'definition' => $this->definition('nation.projects', 'contains_all', ['Urban Planning']),
            'revision' => 2,
            'enabled' => true,
        ]);

        app(AuditService::class)->runAllEnabledRules();

        $this->assertDatabaseHas('audit_results', [
            'audit_rule_id' => $rule->id,
            'nation_id' => $nation->id,
            'city_id' => $city->id,
            'target_type' => 'city',
            'rule_revision' => 2,
        ]);

        $finding = AuditResult::query()->sole();
        $this->assertSame(['Urban Planning'], $finding->details['evidence'][0]['expected']);
        $this->assertContains('Urban Planning', $finding->details['evidence'][0]['observed']);
    }

    public function test_missing_data_sets_warning_status_without_coercing_to_zero(): void
    {
        $this->createNation(score: 900);

        $rule = AuditRule::query()->create([
            'name' => 'Low account credits',
            'target_type' => AuditTargetType::Nation,
            'priority' => AuditPriority::Medium,
            'definition' => $this->definition('nation.account_credits', 'lt', 1),
            'enabled' => true,
        ]);

        app(AuditService::class)->runAllEnabledRules();

        $rule->refresh();
        $this->assertSame(AuditEvaluationStatus::Warning, $rule->last_evaluation_status);
        $this->assertSame(0, $rule->last_match_count);
        $this->assertStringContainsString('missing or invalid data warning', $rule->last_evaluation_error);
        $this->assertNotNull($rule->last_evaluated_at);
        $this->assertDatabaseCount('audit_results', 0);
    }

    public function test_latest_sign_in_dependencies_use_qualified_columns(): void
    {
        $nation = $this->createNation(score: 900);
        NationSignIn::query()->create([
            'nation_id' => $nation->id,
            'mmr_score' => 25,
            'created_at' => now()->subDay(),
        ]);
        NationSignIn::query()->create([
            'nation_id' => $nation->id,
            'mmr_score' => 75,
            'created_at' => now(),
        ]);

        $rule = AuditRule::query()->create([
            'name' => 'Low MMR score',
            'target_type' => AuditTargetType::Nation,
            'priority' => AuditPriority::High,
            'definition' => $this->definition('nation.mmr_score', 'lt', 100),
            'enabled' => true,
        ]);

        app(AuditService::class)->runAllEnabledRules();

        $rule->refresh();
        $finding = AuditResult::query()->sole();

        $this->assertSame(AuditEvaluationStatus::Success, $rule->last_evaluation_status);
        $this->assertSame(1, $rule->last_match_count);
        $this->assertSame(75, $finding->details['evidence'][0]['observed']);
    }

    public function test_metadata_only_changes_keep_revision_and_existing_finding(): void
    {
        Carbon::setTestNow('2026-08-02 12:00:00');
        $this->createNation(score: 900);
        $rule = AuditRule::query()->create([
            'name' => 'Score threshold',
            'description' => 'Initial explanation.',
            'target_type' => AuditTargetType::Nation,
            'priority' => AuditPriority::Medium,
            'definition' => $this->definition('nation.score', 'lt', 1000),
            'revision' => 4,
            'enabled' => true,
        ]);

        app(AuditService::class)->runAllEnabledRules();
        $finding = AuditResult::query()->sole();
        $firstDetectedAt = $finding->first_detected_at->copy();

        $rule->update([
            'description' => 'Clearer member explanation.',
            'remediation_guidance' => 'Increase score before the next audit.',
            'admin_notes' => 'Copy-only update.',
            'priority' => AuditPriority::High,
        ]);
        Carbon::setTestNow('2026-08-02 13:00:00');

        app(AuditService::class)->runAllEnabledRules();

        $rule->refresh();
        $finding->refresh();
        $this->assertSame(4, $rule->revision);
        $this->assertSame(4, $finding->rule_revision);
        $this->assertTrue($finding->first_detected_at->equalTo($firstDetectedAt));
        $this->assertSame(1, AuditResult::query()->count());
    }

    public function test_target_evaluation_failure_preserves_existing_finding(): void
    {
        Carbon::setTestNow('2026-08-02 12:00:00');
        $nation = $this->createNation(score: 900);
        DB::table('nation_accounts')->insert([
            'nation_id' => $nation->id,
            'last_active' => '2026-08-01 12:00:00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $rule = AuditRule::query()->create([
            'name' => 'Activity data available',
            'target_type' => AuditTargetType::Nation,
            'priority' => AuditPriority::Low,
            'definition' => $this->definition('nation.last_activity', 'is_present'),
            'enabled' => true,
        ]);

        app(AuditService::class)->runAllEnabledRules();
        $finding = AuditResult::query()->sole();
        $lastEvaluatedAt = $finding->last_evaluated_at->copy();

        DB::table('nation_accounts')->where('nation_id', $nation->id)->update([
            'last_active' => 'not-a-date',
        ]);
        Carbon::setTestNow('2026-08-02 13:00:00');

        app(AuditService::class)->runAllEnabledRules();

        $rule->refresh();
        $finding->refresh();
        $this->assertSame(AuditEvaluationStatus::Failed, $rule->last_evaluation_status);
        $this->assertStringContainsString('could not be evaluated', $rule->last_evaluation_error);
        $this->assertTrue($finding->last_evaluated_at->equalTo($lastEvaluatedAt));
        $this->assertSame(1, AuditResult::query()->count());
    }

    /**
     * @return array<string, mixed>
     */
    private function definition(string $field, string $operator, mixed $value = null): array
    {
        return [
            'schema_version' => 1,
            'criteria' => [
                'group' => 'all',
                'rules' => [[
                    'id' => '10000000-0000-4000-8000-000000000001',
                    'field' => $field,
                    'operator' => $operator,
                    'value' => $value,
                ]],
            ],
            'exceptions' => [
                'group' => 'any',
                'rules' => [],
            ],
        ];
    }

    private function createNation(float $score): Nation
    {
        return Nation::query()->create([
            'alliance_id' => app(AllianceMembershipService::class)->getPrimaryAllianceId(),
            'alliance_position' => 'MEMBER',
            'alliance_position_id' => 1,
            'nation_name' => 'Testland',
            'leader_name' => 'Tester',
            'continent' => 'AF',
            'war_policy' => 'BLITZKRIEG',
            'war_policy_turns' => 0,
            'domestic_policy' => 'URBANIZATION',
            'domestic_policy_turns' => 0,
            'color' => 'blue',
            'num_cities' => 3,
            'score' => $score,
            'update_tz' => 0,
            'population' => 100000,
            'flag' => 'flag.png',
            'vacation_mode_turns' => 0,
            'beige_turns' => 0,
            'espionage_available' => true,
            'discord' => null,
            'discord_id' => null,
            'turns_since_last_city' => 0,
            'turns_since_last_project' => 0,
            'projects' => 1,
            'project_bits' => '0',
            'wars_won' => 0,
            'wars_lost' => 0,
            'tax_id' => null,
            'alliance_seniority' => 1,
            'gross_national_income' => 0,
            'gross_domestic_product' => 0,
            'vip' => false,
            'commendations' => 0,
            'denouncements' => 0,
            'offensive_wars_count' => 0,
            'defensive_wars_count' => 0,
            'money_looted' => 0,
            'total_infrastructure_destroyed' => 0,
            'total_infrastructure_lost' => 0,
        ]);
    }

    private function createCity(Nation $nation, float $infrastructure): City
    {
        return City::query()->create([
            'nation_id' => $nation->id,
            'name' => 'Capital',
            'date' => now(),
            'infrastructure' => $infrastructure,
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
    }

    private function createTables(): void
    {
        Schema::create('nations', function ($table): void {
            $table->id();
            $table->unsignedBigInteger('alliance_id')->nullable();
            $table->string('alliance_position')->default('MEMBER');
            $table->unsignedInteger('alliance_position_id')->default(0);
            $table->string('nation_name');
            $table->string('leader_name');
            $table->string('continent')->nullable();
            $table->string('war_policy')->nullable();
            $table->unsignedSmallInteger('war_policy_turns')->default(0);
            $table->string('domestic_policy')->nullable();
            $table->unsignedSmallInteger('domestic_policy_turns')->default(0);
            $table->string('color')->nullable();
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

        Schema::create('cities', function ($table): void {
            $table->id();
            $table->foreignId('nation_id')->constrained('nations')->cascadeOnDelete();
            $table->string('name');
            $table->date('date')->nullable();
            $table->float('infrastructure')->default(0);
            $table->float('land')->default(0);
            $table->boolean('powered')->default(false);
            $table->integer('oil_power')->default(0);
            $table->integer('wind_power')->default(0);
            $table->integer('coal_power')->default(0);
            $table->integer('nuclear_power')->default(0);
            $table->integer('coal_mine')->default(0);
            $table->integer('oil_well')->default(0);
            $table->integer('uranium_mine')->default(0);
            $table->integer('barracks')->default(0);
            $table->integer('farm')->default(0);
            $table->integer('police_station')->default(0);
            $table->integer('hospital')->default(0);
            $table->integer('recycling_center')->default(0);
            $table->integer('subway')->default(0);
            $table->integer('supermarket')->default(0);
            $table->integer('bank')->default(0);
            $table->integer('shopping_mall')->default(0);
            $table->integer('stadium')->default(0);
            $table->integer('lead_mine')->default(0);
            $table->integer('iron_mine')->default(0);
            $table->integer('bauxite_mine')->default(0);
            $table->integer('oil_refinery')->default(0);
            $table->integer('aluminum_refinery')->default(0);
            $table->integer('steel_mill')->default(0);
            $table->integer('munitions_factory')->default(0);
            $table->integer('factory')->default(0);
            $table->integer('hangar')->default(0);
            $table->integer('drydock')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('nation_resources', function ($table): void {
            $table->id();
            $table->foreignId('nation_id')->unique()->constrained('nations')->cascadeOnDelete();
            $table->float('money')->default(0);
            $table->float('coal')->default(0);
            $table->float('oil')->default(0);
            $table->float('uranium')->default(0);
            $table->float('iron')->default(0);
            $table->float('bauxite')->default(0);
            $table->float('lead')->default(0);
            $table->float('gasoline')->default(0);
            $table->float('munitions')->default(0);
            $table->float('steel')->default(0);
            $table->float('aluminum')->default(0);
            $table->float('food')->default(0);
            $table->unsignedTinyInteger('credits')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('nation_military', function ($table): void {
            $table->id();
            $table->foreignId('nation_id')->unique()->constrained('nations')->cascadeOnDelete();
            $table->unsignedInteger('soldiers')->default(0);
            $table->unsignedInteger('tanks')->default(0);
            $table->unsignedInteger('aircraft')->default(0);
            $table->unsignedInteger('ships')->default(0);
            $table->unsignedInteger('missiles')->default(0);
            $table->unsignedInteger('nukes')->default(0);
            $table->unsignedInteger('spies')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('nation_accounts', function ($table): void {
            $table->unsignedBigInteger('nation_id')->primary();
            $table->unsignedInteger('credits')->nullable();
            $table->timestamp('last_active')->nullable();
            $table->string('discord_id', 32)->nullable();
            $table->timestamps();
        });

        Schema::create('nation_sign_ins', function ($table): void {
            $table->id();
            $table->foreignId('nation_id')->constrained('nations')->cascadeOnDelete();
            $table->unsignedInteger('mmr_score')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('audit_rules', function ($table): void {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->text('remediation_guidance')->nullable();
            $table->text('admin_notes')->nullable();
            $table->string('target_type');
            $table->string('priority');
            $table->json('definition')->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->boolean('enabled')->default(true);
            $table->string('last_evaluation_status', 32)->default('never_run');
            $table->timestamp('last_evaluated_at')->nullable();
            $table->unsignedInteger('last_match_count')->nullable();
            $table->text('last_evaluation_error')->nullable();
            $table->unsignedInteger('last_evaluation_duration_ms')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });

        Schema::create('audit_results', function ($table): void {
            $table->id();
            $table->foreignId('audit_rule_id')->constrained('audit_rules')->cascadeOnDelete();
            $table->unsignedInteger('rule_revision')->default(1);
            $table->string('target_type');
            $table->string('target_key')->nullable();
            $table->foreignId('nation_id')->nullable()->constrained('nations')->nullOnDelete();
            $table->foreignId('city_id')->nullable()->constrained('cities')->nullOnDelete();
            $table->json('details')->nullable();
            $table->timestamp('first_detected_at');
            $table->timestamp('last_evaluated_at');
            $table->timestamp('acknowledged_at')->nullable();
            $table->unsignedBigInteger('acknowledged_by_user_id')->nullable();
            $table->timestamp('snoozed_until')->nullable();
            $table->unsignedBigInteger('snoozed_by_user_id')->nullable();
            $table->timestamp('waived_until')->nullable();
            $table->unsignedBigInteger('waived_by_user_id')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->string('remediation_note', 500)->nullable();
            $table->timestamps();
            $table->unique(['audit_rule_id', 'target_type', 'target_key']);
        });

        Schema::create('audit_result_events', function ($table): void {
            $table->id();
            $table->unsignedBigInteger('audit_result_id')->nullable();
            $table->unsignedBigInteger('audit_rule_id')->nullable();
            $table->string('target_type', 32);
            $table->string('target_key', 191);
            $table->unsignedBigInteger('nation_id')->nullable();
            $table->unsignedBigInteger('city_id')->nullable();
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->string('event_type', 32);
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();
        });

        Schema::create('offshores', function ($table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->unsignedBigInteger('alliance_id')->nullable();
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('priority')->default(0);
            $table->text('api_key')->nullable();
            $table->text('mutation_key')->nullable();
            $table->timestamps();
        });
    }
}
