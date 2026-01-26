<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ManualTransaction extends Model
{
    public $table = 'manual_transactions';

    protected $fillable = [
        'account_id',
        'admin_id',
        'grant_application_id',
        'city_grant_request_id',
        'correlation_id',
        'money',
        'coal',
        'oil',
        'uranium',
        'lead',
        'iron',
        'bauxite',
        'gasoline',
        'munitions',
        'steel',
        'aluminum',
        'food',
        'note',
        'ip_address',
        'meta',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'meta' => 'array',
    ];

    /**
     * @return BelongsTo
     */
    public function account()
    {
        return $this->belongsTo(Account::class, 'account_id', 'id');
    }

    /**
     * @return BelongsTo
     */
    public function admin()
    {
        return $this->belongsTo(User::class, 'admin_id', 'id');
    }
}
