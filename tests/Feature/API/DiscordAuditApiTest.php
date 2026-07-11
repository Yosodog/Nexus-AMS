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
use Tests\TestCase;

class DiscordAuditApiTest extends TestCase
{
    use RefreshDatabase;

    private const GUILD_ID = '123456789012345678';

    private const DISCORD_ID = '234567890123456789';

    private User $actor;

    private AuditResult $result;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.discord_bot_key' => 'audit-test-key',
            'services.discord.guild_id' => self::GUILD_ID,
        ]);
        Cache::flush();
        Cache::forever('alliances:membership:ids', [777, 888]);

        $nation = Nation::factory()->create(['alliance_id' => 888, 'alliance_position' => 'MEMBER']);
        $this->actor = User::factory()->verified()->create(['nation_id' => $nation->id]);
        DiscordAccount::factory()->create([
            'user_id' => $this->actor->id,
            'discord_id' => self::DISCORD_ID,
            'unlinked_at' => null,
        ]);

        $rule = AuditRule::query()->create([
            'name' => 'Warchest',
            'description' => 'Restore the configured warchest.',
            'target_type' => AuditTargetType::Nation,
            'priority' => AuditPriority::High,
            'expression' => 'true',
            'enabled' => true,
        ]);
        $this->result = AuditResult::query()->create([
            'audit_rule_id' => $rule->id,
            'target_type' => AuditTargetType::Nation,
            'target_key' => 'nation:'.$nation->id,
            'nation_id' => $nation->id,
            'first_detected_at' => now(),
            'last_evaluated_at' => now(),
        ]);
    }

    public function test_member_can_list_acknowledge_and_snooze_own_findings(): void
    {
        $this->withHeaders($this->headers('345678901234567890'))
            ->getJson('/api/v1/discord/me/audits')
            ->assertOk()
            ->assertJsonPath('meta.contract_version', 1)
            ->assertJsonPath('data.0.id', $this->result->id);

        $this->withHeaders($this->headers('456789012345678901'))
            ->postJson('/api/v1/discord/me/audits/'.$this->result->id.'/acknowledge', ['note' => 'Fixing it'])
            ->assertOk()
            ->assertJsonPath('data.acknowledged_at', fn ($value): bool => is_string($value));

        $this->withHeaders($this->headers('567890123456789012'))
            ->postJson('/api/v1/discord/me/audits/'.$this->result->id.'/snooze', ['hours' => 24])
            ->assertOk()
            ->assertJsonPath('data.snoozed_until', fn ($value): bool => is_string($value));
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
        return [
            'Authorization' => 'Bearer audit-test-key',
            'X-Discord-Guild-ID' => self::GUILD_ID,
            'X-Discord-User-ID' => self::DISCORD_ID,
            'X-Discord-Interaction-ID' => $interactionId,
        ];
    }
}
