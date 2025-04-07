<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GrantApplications extends Model
{
    /**
     * @var string
     */
    public $table = "grant_applications";

    /**
     * @var string[]
     */
    protected $fillable = [
        'grant_id',
        'nation_id',
        'account_id',
        'status',
        'approved_at',
        'denied_at',
    ];

    /**
     * @var string[]
     */
    protected $casts = [
        'approved_at' => 'datetime',
        'denied_at' => 'datetime',
    ];

    /**
     * @return BelongsTo
     */
    public function grant(): BelongsTo
    {
        return $this->belongsTo(Grants::class, "grant_id", "id");
    }

    /**
     * @return BelongsTo
     */
    public function nation(): BelongsTo
    {
        return $this->belongsTo(Nations::class, "nation_id", "id");
    }

    /**
     * @return BelongsTo
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Accounts::class, "account_id", "id");
    }
}
