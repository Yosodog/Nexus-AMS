<?php

namespace App\Http\Requests\Admin;

class StoreOffshoreRequest extends OffshoreRequest
{
    protected function isUpdate(): bool
    {
        return false;
    }
}
