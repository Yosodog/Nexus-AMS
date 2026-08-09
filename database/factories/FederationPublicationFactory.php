<?php

namespace Database\Factories;

use App\Domain\Federation\Enums\FederationResourceType;
use App\Domain\Federation\Enums\PublicationStatus;
use App\Models\FederationCoalition;
use App\Models\FederationPublication;
use App\Models\MilcomOperation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<FederationPublication>
 */
class FederationPublicationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::ulid(),
            'milcom_operation_id' => fn (): int => $this->operationId(),
            'federation_coalition_id' => FederationCoalition::factory(),
            'source_installation_id' => (string) Str::ulid(),
            'resource_type' => FederationResourceType::WarPlanSnapshot->value,
            'status' => PublicationStatus::Draft->value,
            'current_version' => 0,
            'current_revision' => 0,
            'source_generation' => 1,
            'created_by' => null,
            'expires_at' => now()->addDays(7),
            'published_at' => null,
            'revoked_at' => null,
        ];
    }

    public function published(): static
    {
        return $this->state(fn (): array => [
            'status' => PublicationStatus::Published->value,
            'current_version' => 1,
            'current_revision' => 1,
            'published_at' => now(),
        ]);
    }

    public function draft(): static
    {
        return $this->state(fn (): array => [
            'status' => PublicationStatus::Draft->value,
            'current_version' => 0,
            'current_revision' => 0,
            'published_at' => null,
            'revoked_at' => null,
        ]);
    }

    public function partiallyRevoked(): static
    {
        return $this->state(fn (): array => [
            'status' => PublicationStatus::PartiallyRevoked->value,
            'current_version' => 1,
            'current_revision' => 2,
            'published_at' => now()->subHour(),
        ]);
    }

    public function revoked(): static
    {
        return $this->state(fn (): array => [
            'status' => PublicationStatus::Revoked->value,
            'current_version' => 1,
            'current_revision' => 2,
            'published_at' => now()->subDay(),
            'revoked_at' => now(),
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (): array => [
            'status' => PublicationStatus::Expired->value,
            'current_version' => 1,
            'current_revision' => 1,
            'expires_at' => now()->subMinute(),
            'published_at' => now()->subDay(),
        ]);
    }

    public function forOperation(MilcomOperation $operation): static
    {
        return $this->state(fn (): array => [
            'milcom_operation_id' => $operation->getKey(),
            'source_generation' => (int) $operation->generation_version,
        ]);
    }

    private function operationId(): int
    {
        $operation = MilcomOperation::query()
            ->where('type', 'plan')
            ->first();

        if ($operation instanceof MilcomOperation) {
            return (int) $operation->getKey();
        }

        return (int) MilcomOperation::query()->create([
            'type' => 'plan',
            'status' => 'draft',
            'current_stage' => 'scope',
            'name' => 'Federation test operation',
            'doctrine_version' => 'fixed-v1',
            'default_war_type' => 'ORDINARY',
            'generation_version' => 1,
            'dispatch_version' => 0,
        ])->getKey();
    }
}
