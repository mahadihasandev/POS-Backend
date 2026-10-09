<?php

return [
    'paths' => ['*'],
    'allowed_methods' => ['*'],
    'allowed_origins' => env('FRONTEND_URL') === '*' 
        ? ['*'] 
        : array_filter(array_map('trim', explode(',', env('FRONTEND_URL', 'http://localhost:3000,https://pos-fontend-kappa.vercel.app')))),
    'allowed_origins_patterns' => [
        '#^https://.*\.vercel\.app$#',
    ],
    'allowed_headers' => ['*'],
    'exposed_headers' => ['X-Response-Time'],
    'max_age' => 86400,
    'supports_credentials' => false,
];
