<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\TenantEventType;
use App\Models\TenantEventReceipt;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<TenantEventReceipt> */
class TenantEventReceiptFactory extends Factory
{
    protected $model = TenantEventReceipt::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $warId = fake()->unique()->numberBetween(1, 2_000_000_000);

        return [
            'delivery_id' => (string) Str::ulid(),
            'event_id' => "world:war:{$warId}:create:v1",
            'contract_version' => 1,
            'event_type' => TenantEventType::WarDeclared,
            'subject_key' => "war:{$warId}",
            'event_digest' => hash('sha256', (string) Str::uuid()),
            'transport_nonce' => bin2hex(random_bytes(16)),
            'trace_id' => (string) Str::ulid(),
            'occurred_at' => now()->subSecond(),
            'published_at' => now(),
            'processed_at' => now(),
        ];
    }
}
