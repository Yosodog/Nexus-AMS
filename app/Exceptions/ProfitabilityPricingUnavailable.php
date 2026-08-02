<?php

namespace App\Exceptions;

use Exception;

class ProfitabilityPricingUnavailable extends Exception
{
    public function __construct(string $message = 'Current market prices are unavailable.')
    {
        parent::__construct($message);
    }
}
