<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ManualDisbursement extends Model
{
    public const TYPE_GRANT = 'grant';

    public const TYPE_CITY_GRANT = 'city_grant';

    public const TYPE_LOAN = 'loan';

    public const TYPE_WAR_AID = 'war_aid';

    /**
     * @var string[]
     */
    protected $fillable = [
        'idempotency_key',
        'type',
        'workflow_id',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'workflow_id' => 'integer',
            'created_by' => 'integer',
        ];
    }
}
