<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Force TLS / HTTPS
    |--------------------------------------------------------------------------
    |
    | When enabled, all non-HTTPS requests are redirected to HTTPS with a 301.
    | Can be toggled via FORCE_HTTPS in .env.
    |
    */
    'force_https' => (bool) env('FORCE_HTTPS', env('APP_ENV') === 'production'),

    /*
    |--------------------------------------------------------------------------
    | Strict-Transport-Security (HSTS)
    |--------------------------------------------------------------------------
    |
    | Tells browsers to strictly use HTTPS for all communications.
    |
    */
    'hsts' => [
        'enabled' => (bool) env('TLS_HSTS_ENABLED', true),
        'max_age' => (int) env('TLS_HSTS_MAX_AGE', 31536000), // 1 year
        'include_subdomains' => (bool) env('TLS_HSTS_SUBDOMAINS', true),
        'preload' => (bool) env('TLS_HSTS_PRELOAD', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Defensive Security Headers
    |--------------------------------------------------------------------------
    */
    'headers' => [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => 'DENY',
        'X-XSS-Protection' => '0',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        'Content-Security-Policy' => env('SECURITY_CSP', "default-src 'self'; frame-ancestors 'none'; object-src 'none'"),
        'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=()',
    ],

    /*
    |--------------------------------------------------------------------------
    | Response Performance Tracking
    |--------------------------------------------------------------------------
    */
    'performance' => [
        'include_timing_header' => true,
        'enable_gzip_compression' => (bool) env('ENABLE_GZIP_COMPRESSION', true),
    ],
];
