<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Models\TenantCallbackDelivery;

interface TenantCallbackTransport
{
    public function send(TenantCallbackDelivery $delivery): void;
}
