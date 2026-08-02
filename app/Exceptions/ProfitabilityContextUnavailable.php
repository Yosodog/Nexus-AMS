<?php

namespace App\Exceptions;

use Exception;

class ProfitabilityContextUnavailable extends Exception
{
    public function __construct(string $message = 'Current profitability calculation inputs are unavailable.')
    {
        parent::__construct($message);
    }
}
