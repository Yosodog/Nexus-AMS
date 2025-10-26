<?php

namespace App\Http\Requests\Admin;

class UpdateOffshoreRequest extends OffshoreRequest
{
    protected function isUpdate(): bool
    {
        return true;
    }
}
