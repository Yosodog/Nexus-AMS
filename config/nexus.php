<?php

use App\Enums\NexusRuntime;

return [
    'runtime' => env('NEXUS_RUNTIME', NexusRuntime::Standalone->value),
];
