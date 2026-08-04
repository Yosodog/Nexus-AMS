<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MilcomEvent extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['payload' => 'array', 'occurred_at' => 'datetime'];
    }

    public function operation(): BelongsTo
    {
        return $this->belongsTo(MilcomOperation::class, 'operation_id');
    }

    public function objective(): BelongsTo
    {
        return $this->belongsTo(MilcomObjective::class, 'objective_id');
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(MilcomIncident::class, 'incident_id');
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(MilcomAssignment::class, 'assignment_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
