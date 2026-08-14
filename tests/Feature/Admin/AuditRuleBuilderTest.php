<?php

namespace Tests\Feature\Admin;

use App\Enums\AuditEvaluationStatus;
use App\Enums\AuditPriority;
use App\Enums\AuditTargetType;
use App\Jobs\RunAuditRuleJob;
use App\Models\AuditResult;
use App\Models\AuditResultEvent;
use App\Models\AuditRule;
use App\Models\Nation;
use App\Models\User;
use App\Services\AllianceMembershipService;
use App\Services\Audit\AuditRuleDefinitionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\BuildsTestUsers;
use Tests\TestCase;

class AuditRuleBuilderTest extends TestCase
{
    use BuildsTestUsers;
    use RefreshDatabase;

    private const ALLIANCE_ID = 777;

    private const PRIMARY_NODE_ID = '00000000-0000-4000-8000-000000000001';

    private const SECONDARY_NODE_ID = '00000000-0000-4000-8000-000000000002';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.pw.alliance_id', self::ALLIANCE_ID);
        app(AllianceMembershipService::class)->clear();
        Queue::fake();
    }

    public function test_preview_requires_a_manage_audits_admin(): void
    {
        $definition = $this->definition('nation.score', 'gte', 500);

        $this->postJson(route('admin.audits.rules.preview'), [
            'target_type' => AuditTargetType::Nation->value,
            'definition' => $definition,
        ])->assertUnauthorized();

        $adminWithoutPermission = $this->createAdmin();

        $this->actingAs($adminWithoutPermission)
            ->postJson(route('admin.audits.rules.preview'), [
                'target_type' => AuditTargetType::Nation->value,
                'definition' => $definition,
            ])
            ->assertForbidden();

        $viewer = $this->createAdmin(['view-audits']);

        $this->actingAs($viewer)
            ->postJson(route('admin.audits.rules.preview'), [
                'target_type' => AuditTargetType::Nation->value,
                'definition' => $definition,
            ])
            ->assertForbidden();
    }

    public function test_editor_exposes_the_same_csrf_token_to_javascript_and_the_form(): void
    {
        $admin = $this->createAdmin(['manage-audits']);
        $response = $this->actingAs($admin)->get(route('admin.audits.rules.create'))->assertOk();
        $html = $response->getContent();

        $this->assertMatchesRegularExpression('/<meta name="csrf-token" content="[^"]+">/', $html);
        $this->assertMatchesRegularExpression('/<input type="hidden" name="_token" value="[^"]+"/', $html);

        preg_match('/<meta name="csrf-token" content="([^"]+)">/', $html, $metaToken);
        preg_match('/<input type="hidden" name="_token" value="([^"]+)"/', $html, $formToken);

        $this->assertSame($metaToken[1], $formToken[1]);
    }

    public function test_preview_returns_normalized_impact_samples_evidence_warnings_fingerprint_and_an_encrypted_token(): void
    {
        $admin = $this->createAdmin(['manage-audits']);
        Nation::factory()->count(25)->create([
            'alliance_id' => self::ALLIANCE_ID,
            'alliance_position' => 'MEMBER',
            'vacation_mode_turns' => 0,
            'score' => 1000,
        ]);

        $definition = $this->definitionWithRules('any', [
            $this->condition('score-condition', 'nation.score', 'gte', '500'),
            $this->condition('credits-condition', 'nation.account_credits', 'gt', 0),
        ]);

        $response = $this->actingAs($admin)
            ->postJson(route('admin.audits.rules.preview'), [
                'target_type' => AuditTargetType::Nation->value,
                'definition' => $definition,
            ])
            ->assertOk()
            ->assertJsonStructure([
                'definition' => ['schema_version', 'criteria', 'exceptions'],
                'plain_language_summary',
                'match_count',
                'samples' => [
                    '*' => [
                        'id',
                        'label',
                        'secondary_label',
                        'target_key',
                        'evidence' => [
                            '*' => [
                                'scope',
                                'condition',
                                'field_label',
                                'operator_label',
                                'observed',
                                'observed_display',
                                'expected',
                                'expected_display',
                                'matched',
                                'member_safe',
                            ],
                        ],
                        'warnings',
                    ],
                ],
                'warnings',
                'evaluation_time_ms',
                'definition_fingerprint',
                'confirmation_token',
                'sample_limit',
            ])
            ->assertJsonPath('match_count', 25)
            ->assertJsonPath('sample_limit', 20)
            ->assertJsonCount(20, 'samples')
            ->assertJsonPath('definition.criteria.rules.0.value', 500);

        $this->assertNotEmpty($response->json('warnings'));
        $this->assertCount(2, $response->json('samples.0.evidence'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $response->json('definition_fingerprint'));

        $token = $response->json('confirmation_token');
        $this->assertIsString($token);
        $this->assertNull(json_decode($token, true));

        $payload = json_decode(Crypt::decryptString($token), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('audit_rule_impact_confirmation', $payload['purpose']);
        $this->assertSame($admin->id, $payload['user_id']);
        $this->assertSame(AuditTargetType::Nation->value, $payload['target_type']);
        $this->assertSame($response->json('definition_fingerprint'), $payload['definition_fingerprint']);
        $this->assertGreaterThan(now()->timestamp, $payload['expires_at']);
    }

    public function test_enabled_create_requires_nonempty_valid_criteria_and_impact_confirmation(): void
    {
        $admin = $this->createAdmin(['manage-audits']);
        $emptyDefinition = $this->emptyDefinition();
        $definition = $this->definition('nation.score', 'gte', 500);

        $this->actingAs($admin)
            ->from(route('admin.audits.rules.create'))
            ->post(route('admin.audits.rules.store'), $this->rulePayload($emptyDefinition, true))
            ->assertRedirect(route('admin.audits.rules.create'))
            ->assertSessionHasErrors('definition');

        $this->actingAs($admin)
            ->from(route('admin.audits.rules.create'))
            ->post(route('admin.audits.rules.store'), $this->rulePayload($definition, true))
            ->assertRedirect(route('admin.audits.rules.create'))
            ->assertSessionHasErrors('impact_confirmation_token');

        $token = $this->previewToken($admin, AuditTargetType::Nation, $definition);

        $this->actingAs($admin)
            ->post(route('admin.audits.rules.store'), $this->rulePayload($definition, true, [
                'impact_confirmation_token' => $token,
            ]))
            ->assertRedirect(route('admin.audits.rules.index'))
            ->assertSessionHasNoErrors();

        $rule = AuditRule::query()->where('name', 'Rule builder feature test')->firstOrFail();

        $this->assertTrue($rule->enabled);
        $this->assertSame(1, $rule->revision);
        $this->assertSame(AuditEvaluationStatus::Pending, $rule->last_evaluation_status);
        $this->assertSame($admin->id, $rule->created_by);
        Queue::assertPushed(RunAuditRuleJob::class, fn (RunAuditRuleJob $job): bool => $job->auditRuleId === $rule->id);
    }

    public function test_expired_confirmation_token_is_rejected(): void
    {
        $admin = $this->createAdmin(['manage-audits']);
        $definition = $this->definition('nation.score', 'gte', 500);
        $token = $this->previewToken($admin, AuditTargetType::Nation, $definition);

        $this->travel(11)->minutes();

        $this->actingAs($admin)
            ->from(route('admin.audits.rules.create'))
            ->post(route('admin.audits.rules.store'), $this->rulePayload($definition, true, [
                'impact_confirmation_token' => $token,
            ]))
            ->assertRedirect(route('admin.audits.rules.create'))
            ->assertSessionHasErrors('impact_confirmation_token');
    }

    public function test_confirmation_token_is_bound_to_the_admin(): void
    {
        $issuingAdmin = $this->createAdmin(['manage-audits']);
        $submittingAdmin = $this->createAdmin(['manage-audits']);
        $definition = $this->definition('nation.score', 'gte', 500);
        $token = $this->previewToken($issuingAdmin, AuditTargetType::Nation, $definition);

        $this->actingAs($submittingAdmin)
            ->from(route('admin.audits.rules.create'))
            ->post(route('admin.audits.rules.store'), $this->rulePayload($definition, true, [
                'impact_confirmation_token' => $token,
            ]))
            ->assertRedirect(route('admin.audits.rules.create'))
            ->assertSessionHasErrors('impact_confirmation_token');
    }

    public function test_confirmation_token_is_bound_to_the_target_type(): void
    {
        $admin = $this->createAdmin(['manage-audits']);
        $nationDefinition = $this->definition('nation.score', 'gte', 500);
        $cityDefinition = $this->definition('city.infrastructure', 'gte', 1000);
        $token = $this->previewToken($admin, AuditTargetType::Nation, $nationDefinition);

        $this->actingAs($admin)
            ->from(route('admin.audits.rules.create'))
            ->post(route('admin.audits.rules.store'), $this->rulePayload($cityDefinition, true, [
                'target_type' => AuditTargetType::City->value,
                'impact_confirmation_token' => $token,
            ]))
            ->assertRedirect(route('admin.audits.rules.create'))
            ->assertSessionHasErrors('impact_confirmation_token');
    }

    public function test_confirmation_token_is_bound_to_the_definition_fingerprint(): void
    {
        $admin = $this->createAdmin(['manage-audits']);
        $previewedDefinition = $this->definition('nation.score', 'gte', 500);
        $submittedDefinition = $this->definition('nation.score', 'gte', 501);
        $token = $this->previewToken($admin, AuditTargetType::Nation, $previewedDefinition);

        $this->actingAs($admin)
            ->from(route('admin.audits.rules.create'))
            ->post(route('admin.audits.rules.store'), $this->rulePayload($submittedDefinition, true, [
                'impact_confirmation_token' => $token,
            ]))
            ->assertRedirect(route('admin.audits.rules.create'))
            ->assertSessionHasErrors('impact_confirmation_token');
    }

    public function test_disabled_empty_draft_can_be_saved_without_impact_confirmation(): void
    {
        $admin = $this->createAdmin(['manage-audits']);

        $this->actingAs($admin)
            ->post(route('admin.audits.rules.store'), $this->rulePayload($this->emptyDefinition(), false))
            ->assertRedirect(route('admin.audits.rules.index'))
            ->assertSessionHasNoErrors();

        $rule = AuditRule::query()->where('name', 'Rule builder feature test')->firstOrFail();

        $this->assertFalse($rule->enabled);
        $this->assertSame($this->emptyDefinition(), $rule->definition);
        $this->assertSame(AuditEvaluationStatus::NeverRun, $rule->last_evaluation_status);
        Queue::assertNothingPushed();
    }

    public function test_metadata_only_enabled_edit_preserves_revision_and_findings_without_a_token(): void
    {
        $admin = $this->createAdmin(['manage-audits']);
        $definition = $this->definition('nation.score', 'gte', 500);
        $rule = $this->createRule($admin, $definition, enabled: true, revision: 4);
        $finding = $this->createFinding($rule);

        $this->actingAs($admin)
            ->put(route('admin.audits.rules.update', $rule), $this->rulePayload($definition, true, [
                'name' => 'Renamed metadata-only rule',
                'description' => 'Updated member explanation.',
                'priority' => AuditPriority::High->value,
            ]))
            ->assertRedirect(route('admin.audits.rules.index'))
            ->assertSessionHasNoErrors();

        $rule->refresh();

        $this->assertSame('Renamed metadata-only rule', $rule->name);
        $this->assertSame(4, $rule->revision);
        $this->assertDatabaseHas('audit_results', ['id' => $finding->id]);
        $this->assertDatabaseMissing('audit_result_events', [
            'audit_result_id' => $finding->id,
            'event_type' => 'rule_revised',
        ]);
        Queue::assertNothingPushed();
    }

    public function test_enabled_logic_edit_with_confirmation_revises_closes_findings_records_event_and_dispatches_evaluation(): void
    {
        $admin = $this->createAdmin(['manage-audits']);
        $originalDefinition = $this->definition('nation.score', 'gte', 500);
        $newDefinition = $this->definition('nation.score', 'gte', 750);
        $rule = $this->createRule($admin, $originalDefinition, enabled: true, revision: 4);
        $finding = $this->createFinding($rule);
        $token = $this->previewToken($admin, AuditTargetType::Nation, $newDefinition);

        $this->actingAs($admin)
            ->put(route('admin.audits.rules.update', $rule), $this->rulePayload($newDefinition, true, [
                'impact_confirmation_token' => $token,
            ]))
            ->assertRedirect(route('admin.audits.rules.index'))
            ->assertSessionHasNoErrors();

        $rule->refresh();

        $this->assertSame(5, $rule->revision);
        $this->assertSame(AuditEvaluationStatus::Pending, $rule->last_evaluation_status);
        $this->assertDatabaseMissing('audit_results', ['id' => $finding->id]);
        $this->assertDatabaseHas('audit_result_events', [
            'audit_result_id' => $finding->id,
            'audit_rule_id' => $rule->id,
            'event_type' => 'rule_revised',
            'actor_user_id' => $admin->id,
        ]);

        $event = AuditResultEvent::query()->where('audit_result_id', $finding->id)->firstOrFail();
        $this->assertSame(4, $event->metadata['previous_revision']);
        $this->assertSame(5, $event->metadata['new_revision']);
        Queue::assertPushed(RunAuditRuleJob::class, fn (RunAuditRuleJob $job): bool => $job->auditRuleId === $rule->id);
    }

    public function test_disabling_an_enabled_rule_records_rule_disabled_and_closes_findings(): void
    {
        $admin = $this->createAdmin(['manage-audits']);
        $definition = $this->definition('nation.score', 'gte', 500);
        $rule = $this->createRule($admin, $definition, enabled: true, revision: 3);
        $finding = $this->createFinding($rule);

        $this->actingAs($admin)
            ->put(route('admin.audits.rules.update', $rule), $this->rulePayload($definition, false))
            ->assertRedirect(route('admin.audits.rules.index'))
            ->assertSessionHasNoErrors();

        $rule->refresh();

        $this->assertFalse($rule->enabled);
        $this->assertSame(3, $rule->revision);
        $this->assertSame(AuditEvaluationStatus::NeverRun, $rule->last_evaluation_status);
        $this->assertDatabaseMissing('audit_results', ['id' => $finding->id]);
        $this->assertDatabaseHas('audit_result_events', [
            'audit_result_id' => $finding->id,
            'audit_rule_id' => $rule->id,
            'event_type' => 'rule_disabled',
            'actor_user_id' => $admin->id,
        ]);
        Queue::assertNothingPushed();
    }

    public function test_disabling_an_already_disabled_rule_closes_stale_findings(): void
    {
        $admin = $this->createAdmin(['manage-audits']);
        $definition = $this->definition('nation.score', 'gte', 500);
        $rule = $this->createRule($admin, $definition, enabled: false, revision: 3);
        $finding = $this->createFinding($rule);

        $this->actingAs($admin)
            ->delete(route('admin.audits.rules.destroy', $rule))
            ->assertRedirect(route('admin.audits.rules.index'))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('audit_results', ['id' => $finding->id]);
        $this->assertDatabaseHas('audit_result_events', [
            'audit_result_id' => $finding->id,
            'audit_rule_id' => $rule->id,
            'event_type' => 'rule_disabled',
            'actor_user_id' => $admin->id,
        ]);
        Queue::assertNothingPushed();
    }

    public function test_reenabling_a_rule_requires_confirmation_and_starts_a_fresh_revision(): void
    {
        $admin = $this->createAdmin(['manage-audits']);
        $definition = $this->definition('nation.score', 'gte', 500);
        $rule = $this->createRule($admin, $definition, enabled: false, revision: 3);

        $this->actingAs($admin)
            ->from(route('admin.audits.rules.edit', $rule))
            ->put(route('admin.audits.rules.update', $rule), $this->rulePayload($definition, true))
            ->assertRedirect(route('admin.audits.rules.edit', $rule))
            ->assertSessionHasErrors('impact_confirmation_token');

        $rule->refresh();
        $this->assertFalse($rule->enabled);
        $this->assertSame(3, $rule->revision);

        $token = $this->previewToken($admin, AuditTargetType::Nation, $definition);

        $this->actingAs($admin)
            ->put(route('admin.audits.rules.update', $rule), $this->rulePayload($definition, true, [
                'impact_confirmation_token' => $token,
            ]))
            ->assertRedirect(route('admin.audits.rules.index'))
            ->assertSessionHasNoErrors();

        $rule->refresh();

        $this->assertTrue($rule->enabled);
        $this->assertSame(4, $rule->revision);
        $this->assertSame(AuditEvaluationStatus::Pending, $rule->last_evaluation_status);
        Queue::assertPushed(RunAuditRuleJob::class, fn (RunAuditRuleJob $job): bool => $job->auditRuleId === $rule->id);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rules
     * @return array<string, mixed>
     */
    private function definitionWithRules(string $group, array $rules): array
    {
        return [
            'schema_version' => 1,
            'criteria' => [
                'group' => $group,
                'rules' => $rules,
            ],
            'exceptions' => [
                'group' => 'any',
                'rules' => [],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function definition(string $field, string $operator, mixed $value): array
    {
        return $this->definitionWithRules('all', [
            $this->condition('primary-condition', $field, $operator, $value),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyDefinition(): array
    {
        return $this->definitionWithRules('all', []);
    }

    /**
     * @return array<string, mixed>
     */
    private function condition(string $id, string $field, string $operator, mixed $value): array
    {
        return [
            'id' => $id === 'credits-condition' ? self::SECONDARY_NODE_ID : self::PRIMARY_NODE_ID,
            'field' => $field,
            'operator' => $operator,
            'value' => $value,
        ];
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function rulePayload(array $definition, bool $enabled, array $overrides = []): array
    {
        return [
            'name' => 'Rule builder feature test',
            'description' => 'Member-facing explanation.',
            'remediation_guidance' => 'Correct the observed value.',
            'admin_notes' => 'Feature test notes.',
            'target_type' => AuditTargetType::Nation->value,
            'priority' => AuditPriority::Medium->value,
            'definition' => $definition,
            'enabled' => $enabled,
            ...$overrides,
        ];
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function previewToken(User $admin, AuditTargetType $targetType, array $definition): string
    {
        $response = $this->actingAs($admin)
            ->postJson(route('admin.audits.rules.preview'), [
                'target_type' => $targetType->value,
                'definition' => $definition,
            ])
            ->assertOk();

        return (string) $response->json('confirmation_token');
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function createRule(
        User $admin,
        array $definition,
        bool $enabled,
        int $revision,
    ): AuditRule {
        return AuditRule::query()->create([
            'name' => 'Existing audit rule',
            'description' => 'Existing member explanation.',
            'remediation_guidance' => 'Existing remediation.',
            'admin_notes' => 'Existing notes.',
            'target_type' => AuditTargetType::Nation,
            'priority' => AuditPriority::Medium,
            'definition' => $definition,
            'revision' => $revision,
            'enabled' => $enabled,
            'last_evaluation_status' => $enabled
                ? AuditEvaluationStatus::Success
                : AuditEvaluationStatus::NeverRun,
            'last_match_count' => $enabled ? 1 : 0,
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
        ]);
    }

    private function createFinding(AuditRule $rule): AuditResult
    {
        $nation = Nation::factory()->create([
            'alliance_id' => self::ALLIANCE_ID,
            'alliance_position' => 'MEMBER',
            'vacation_mode_turns' => 0,
        ]);

        return AuditResult::query()->create([
            'audit_rule_id' => $rule->id,
            'rule_revision' => $rule->revision,
            'target_type' => AuditTargetType::Nation,
            'target_key' => "nation:{$nation->id}",
            'nation_id' => $nation->id,
            'city_id' => null,
            'details' => [
                'rule_revision' => $rule->revision,
                'summary' => app(AuditRuleDefinitionService::class)->summarize($rule->definition, $rule->target_type),
                'evidence' => [],
                'evaluated_at' => now()->toIso8601String(),
            ],
            'first_detected_at' => now()->subDay(),
            'last_evaluated_at' => now(),
            'acknowledged_at' => now()->subHour(),
            'acknowledged_by_user_id' => $rule->updated_by,
            'snoozed_until' => now()->addDay(),
            'snoozed_by_user_id' => $rule->updated_by,
            'waived_until' => now()->addDays(2),
            'waived_by_user_id' => $rule->updated_by,
            'due_at' => now()->addDays(3),
            'remediation_note' => 'Existing remediation state.',
        ]);
    }

    /**
     * @param  array<int, string>  $permissions
     */
    private function createAdmin(array $permissions = []): User
    {
        $admin = $this->createVerifiedAdmin();
        $this->attachDiscordAccount($admin);

        return $permissions === [] ? $admin : $this->grantPermissions($admin, $permissions);
    }
}
