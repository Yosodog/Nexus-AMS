<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Accounts extends Model
{
    public $table = "accounts";

    protected array $resources = [
        "money", "coal", "oil", "uranium", "iron", "bauxite", "lead",
        "gasoline", "munitions", "steel", "aluminum", "food"
    ];

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function nation()
    {
        return $this->belongsTo(Nations::class, "nation_id", "id");
    }

    /**
     * Checks if the account is completely empty.
     *
     * @return bool
     */
    public function isEmpty(): bool
    {
        foreach ($this->resources as $res)
        {
            if ($this->$res != 0)
                return false;
        }

        return true;
    }
}
