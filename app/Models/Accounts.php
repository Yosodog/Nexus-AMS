<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Accounts extends Model
{
    public $table = "accounts";

    protected array $resources = [
        "money",
        "coal",
        "oil",
        "uranium",
        "iron",
        "bauxite",
        "lead",
        "gasoline",
        "munitions",
        "steel",
        "aluminum",
        "food"
    ];

    /**
     * @return BelongsTo
     */
    public function nation()
    {
        return $this->belongsTo(Nations::class, "nation_id", "id");
    }

    /**
     * @return BelongsTo
     */
    public function user()
    {
        return $this->belongsTo(User::class, "nation_id", "nation_id");
    }

    /**
     * Checks if the account is completely empty.
     *
     * @return bool
     */
    public function isEmpty(): bool
    {
        foreach ($this->resources as $res) {
            if ($this->$res != 0) {
                return false;
            }
        }

        return true;
    }
}
