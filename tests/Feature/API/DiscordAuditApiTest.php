<?php

namespace Tests\Feature\API;

use App\Enums\AuditPriority;
use App\Enums\AuditTargetType;
use App\Models\AuditResult;
use App\Models\AuditRule;
use App\Models\DiscordAccount;
use App\Models\Nation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\SignsDiscordInteractions;
use Tests\TestCase;

class DiscordAuditApiTest extends TestCase
{
    use RefreshDatabase;
    use SignsDiscordInteractions;

    private const GUILD_ID = '123456789012345678';

    private const DISCORD_ID = '234567890123456789';

    private User $actor;

    private AuditResult $result;

    private Nation $nation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureDiscordInteractionSigning();

        config([
            'services.discord_bot_key' => 'audit-test-key',
            'services.discord.guild_id' => self::GUILD_ID,
        ]);
        Cache::flush();
        Cache::forever('alliances:membership:ids', [777, 888]);

        $this->nation = Nation::factory()->create(['alliance_id' => 888, 'alliance_position' => 'MEMBER']);
        $this->actor = User::factory()->verified()->create(['nation_id' => $this->nation->id]);
        DiscordAccount::factory()->create([
            'user_id' => $this->actor->id,
            'discord_id' => self::DISCORD_ID,
            'unlinked_at' => null,
        ]);

        $rule = AuditRule::query()->create([
            'name' => 'Warchest',
            'description' => 'Restore the configured warchest.',
            'remediation_guidance' => 'Deposit enough resources to meet the warchest requirement.',
            'admin_notes' => 'Discord audit API fixture.',
            'target_type' => AuditTargetType::Nation,
            'priority' => AuditPriority::High,
            'definition' => $this->definition(),
            'revision' => 3,
            'enabled' => true,
        ]);
        $evaluatedAt = now()->subMinutes(5);
        $this->result = AuditResult::query()->create([
            'audit_rule_id' => $rule->id,
            'rule_revision' => 3,
            'target_type' => AuditTargetType::Nation,
            'target_key' => 'nation:'.$this->nation->id,
            'nation_id' => $this->nation->id,
            'details' => [
                'rule_revision' => 3,
                'summary' => 'Alert when score is greater than 1,000 score.',
                'evidence' => [
                    [
                        'scope' => 'criteria',
                        'condition' => 'Score is greater than 1,000 score',
                        'field_label' => 'Score',
                        'operator_label' => 'is greater than',
                        'observed' => 1250,
                        'observed_display' => '1,250 score',
                        'expected' => 1000,
                        'expected_display' => '1,000 score',
                        'matched' => true,
                        'member_safe' => true,
                    ],
                    [
                        'scope' => 'criteria',
                        'condition' => 'Internal risk score is elevated',
                        'field_label' => 'Internal risk score',
                        'operator_label' => 'is greater than',
                        'observed' => 99,
                        'observed_display' => '99',
                        'expected' => 50,
                        'expected_display' => '50',
                        'matched' => true,
                        'member_safe' => false,
                        'internal_field_key' => 'nation.internal_risk_score',
                    ],
                ],
                'evaluated_at' => $evaluatedAt->toIso8601String(),
            ],
            'first_detected_at' => now(),
            'last_evaluated_at' => $evaluatedAt,
        ]);
    }

    public function test_member_can_list_acknowledge_and_snooze_own_findings(): void
    {
        $this->withHeaders($this->headers('345678901234567890'))
            ->getJson('/api/v1/discord/me/audits')
            ->assertOk()
            ->assertJsonPath('meta.contract_version', 1)
            ->assertJsonPath('data.0.id', $this->result->id)
            ->assertJsonPath('data.0.name', 'Warchest')
            ->assertJsonPath('data.0.description', 'Restore the configured warchest.')
            ->assertJsonPath('data.0.priority', AuditPriority::High->value)
            ->assertJsonPath('data.0.target_type', AuditTargetType::Nation->value)
            ->assertJsonPath('data.0.target', 'Nation-wide')
            ->assertJsonPath('data.0.plain_language_summary', 'Alert when score is greater than 1,000 score.')
            ->assertJsonPath('data.0.remediation_guidance', 'Deposit enough resources to meet the warchest requirement.')
            ->assertJsonPath('data.0.rule_revision', 3)
            ->assertJsonPath('data.0.last_evaluated_at', $this->result->last_evaluated_at->toIso8601String())
            ->assertJsonCount(1, 'data.0.evidence')
            ->assertJsonPath('data.0.evidence.0.field_label', 'Score')
            ->assertJsonMissing(['field_label' => 'Internal risk score'])
            ->assertJsonMissing(['internal_field_key' => 'nation.internal_risk_score'])
            ->assertJsonStructure([
                'data' => [[
                    'id',
                    'name',
                    'description',
                    'priority',
                    'target_type',
                    'target',
                    'first_detected_at',
                    'acknowledged_at',
                    'snoozed_until',
                    'waived_until',
                    'due_at',
                    'plain_language_summary',
                    'remediation_guidance',
                    'rule_revision',
                    'last_evaluated_at',
                    'evidence',
                ]],
                'meta' => ['contract_version'],
            ]);

        $this->withHeaders($this->headers('456789012345678901'))
            ->postJson('/api/v1/discord/me/audits/'.$this->result->id.'/acknowledge', ['note' => 'Fixing it'])
            ->assertOk()
            ->assertJsonPath('data.acknowledged_at', fn ($value): bool => is_string($value));

        $this->withHeaders($this->headers('567890123456789012'))
            ->postJson('/api/v1/discord/me/audits/'.$this->result->id.'/snooze', ['hours' => 24])
            ->assertOk()
            ->assertJsonPath('data.snoozed_until', fn ($value): bool => is_string($value));
    }

    public function test_member_only_receives_findings_owned_by_their_nation(): void
    {
        $otherNation = Nation::factory()->create(['alliance_id' => 777, 'alliance_position' => 'MEMBER']);
        $otherResult = AuditResult::query()->create([
            'audit_rule_id' => $this->result->audit_rule_id,
            'rule_revision' => 3,
            'target_type' => AuditTargetType::Nation,
            'target_key' => 'nation:'.$otherNation->id,
            'nation_id' => $otherNation->id,
            'details' => $this->result->details,
            'first_detected_at' => now(),
            'last_evaluated_at' => now(),
        ]);

        $this->withHeaders($this->headers('789012345678901234'))
            ->getJson('/api/v1/discord/me/audits')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $this->result->id)
            ->assertJsonMissing(['id' => $otherResult->id]);
    }

    public function test_applicant_cannot_use_audit_api(): void
    {
        $this->actor->nation()->update(['alliance_position' => 'APPLICANT']);

        $this->withHeaders($this->headers('678901234567890123'))
            ->getJson('/api/v1/discord/me/audits')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'forbidden');
    }

    /** @return array<string, string> */
    private function headers(string $interactionId): array
    {
        return $this->signedDiscordInteractionHeaders(
            'audit-test-key',
            self::GUILD_ID,
            self::DISCORD_ID,
            $interactionId,
            'audit',
        );
    }

    /** @return array<string, mixed> */
    private function definition(): array
    {
        return [
            'schema_version' => 1,
            'criteria' => [
                'group' => 'all',
                'rules' => [[
                    'id' => 'ddff6eb3-ef54-4999-a2ed-f36f37611875',
                    'field' => 'nation.score',
                    'operator' => 'gt',
                    'value' => 1000,
                ]],
            ],
            'exceptions' => [
                'group' => 'any',
                'rules' => [],
            ],
        ];
    }
}
