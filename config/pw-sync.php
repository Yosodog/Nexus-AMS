<?php

return [
    'chunk_size' => 100,
    'staleness' => [
        App\Models\Nation::class => 48,
        App\Models\Alliance::class => 48,
        App\Models\City::class => 48,
    ],
];
