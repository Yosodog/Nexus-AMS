<?php

declare(strict_types=1);

namespace App\Services\TenantEvents;

use App\Events\WarDeclared;
use App\Models\War;
use App\Models\WarDeclarationReceipt;

final class WarDeclarationReactionService
{
    public function react(War $war): bool
    {
        return $war->getConnection()->transaction(function () use ($war): bool {
            $receipt = WarDeclarationReceipt::query()->firstOrCreate([
                'war_id' => (int) $war->getKey(),
            ]);

            if (! $receipt->wasRecentlyCreated) {
                return false;
            }

            event(new WarDeclared(
                warId: (int) $war->getKey(),
                attackerNationId: (int) $war->getAttribute('att_id'),
                attackerAllianceId: $this->nullableInteger($war->getAttribute('att_alliance_id')),
                attackerAlliancePosition: $this->nullableString($war->getAttribute('att_alliance_position')),
                defenderNationId: (int) $war->getAttribute('def_id'),
                defenderAllianceId: $this->nullableInteger($war->getAttribute('def_alliance_id')),
                defenderAlliancePosition: $this->nullableString($war->getAttribute('def_alliance_position')),
            ));

            return true;
        });
    }

    private function nullableInteger(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }

    private function nullableString(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }
}
