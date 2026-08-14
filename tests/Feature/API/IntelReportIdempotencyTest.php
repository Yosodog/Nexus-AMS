<?php

namespace Tests\Feature\API;

use App\Models\IntelReport;
use App\Services\IntelReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SignsDiscordInteractions;
use Tests\TestCase;

class IntelReportIdempotencyTest extends TestCase
{
    use RefreshDatabase;
    use SignsDiscordInteractions;

    private const BOT_TOKEN = 'intel-test-key';

    private const GUILD_ID = '123456789012345678';

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureDiscordInteractionSigning();
        config([
            'services.discord_bot_key' => self::BOT_TOKEN,
            'services.discord.guild_id' => self::GUILD_ID,
        ]);
    }

    public function test_duplicate_report_hash_returns_the_existing_report(): void
    {
        $service = app(IntelReportService::class);
        $payload = [
            'nation_name' => 'Example Nation',
            'raw_text' => "Example Nation\nMoney: \$1,000,000",
            'money' => 1_000_000,
            'was_detected' => false,
        ];

        $first = $service->store($payload, 'discord');
        $second = $service->store($payload, 'discord');

        $this->assertSame($first->id, $second->id);
        $this->assertSame($first->hash, $second->hash);
        $this->assertDatabaseCount('intel_reports', 1);
        $this->assertSame(1_000_000.0, (float) IntelReport::query()->firstOrFail()->money);
    }

    public function test_null_source_uses_the_discord_default_instead_of_throwing(): void
    {
        $this->withHeaders($this->intelHeaders())
            ->postJson('/api/v1/discord/intel', [
                'report' => $this->validReport(),
                'source' => null,
            ])
            ->assertCreated();

        $this->assertDatabaseHas('intel_reports', ['source' => 'discord']);
    }

    public function test_oversized_parsed_values_are_rejected_without_a_database_error(): void
    {
        $oversizedMoneyReport = str_replace('$1,000.00', '$1,000,000,000,000.00', $this->validReport());

        $this->withHeaders($this->intelHeaders())
            ->postJson('/api/v1/discord/intel', ['report' => $oversizedMoneyReport])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'A resource amount in the intel report exceeds the supported range.');

        $this->assertDatabaseCount('intel_reports', 0);
    }

    private function validReport(): string
    {
        return 'Your spies gathered intelligence about Example Nation. Example Nation has $1,000.00, 2 coal, 3 oil, 4 uranium, 5 lead, 6 iron, 7 bauxite, 8 gasoline, 9 munitions, 10 steel, 11 aluminum and 12 food. The operation cost you $50. 2 of your spies were captured.';
    }

    /** @return array<string, string> */
    private function intelHeaders(): array
    {
        return $this->signedDiscordServiceHeaders(
            self::BOT_TOKEN,
            self::GUILD_ID,
            'intel.report',
        );
    }
}
