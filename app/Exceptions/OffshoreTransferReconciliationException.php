<?php

namespace App\Exceptions;

use App\Models\OffshoreTransfer;

class OffshoreTransferReconciliationException extends OffshoreTransferException
{
    public function __construct(public readonly OffshoreTransfer $transfer)
    {
        parent::__construct('This transfer already exists and must be reconciled before it can be retried.');
    }
}
