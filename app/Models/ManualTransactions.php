<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ManualTransactions extends Model
{
    public $table = "manual_transactions";

    protected $fillable = [
        'account_id', 'admin_id', 'money', 'coal', 'oil', 'uranium',
        'lead', 'iron', 'bauxite', 'gasoline', 'munitions',
        'steel', 'aluminum', 'food', 'note', 'ip_address'
    ];

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function account()
    {
        return $this->belongsTo(Accounts::class, "account_id", "id");
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function admin()
    {
        return $this->belongsTo(User::class, "admin_id", "id");
    }
}
