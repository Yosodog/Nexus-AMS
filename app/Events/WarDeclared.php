<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Raised when the Politics & War API reports a new war declaration.
 */
class WarDeclared
{
    use Dispatchable, SerializesModels;

    /**
     * @param  int  $warId  War identifier from PW.
     * @param  int  $attackerNationId  Aggressor nation ID.
     * @param  int|null  $attackerAllianceId  Aggressor alliance ID (nullable for no alliance).
     * @param  int  $defenderNationId  Defender nation ID.
     * @param  int|null  $defenderAllianceId  Defender alliance ID.
     */
    public function __construct(
        public readonly int $warId,
        public readonly int $attackerNationId,
        public readonly ?int $attackerAllianceId,
        public readonly int $defenderNationId,
        public readonly ?int $defenderAllianceId
    ) {}
}
