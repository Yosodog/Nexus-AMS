<?php

return [
    'indexing_enabled' => env(
        'SEO_INDEXING_ENABLED',
        env('APP_ENV', 'production') === 'production',
    ),
];
