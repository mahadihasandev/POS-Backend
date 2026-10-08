<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | JWT Secret Key
    |--------------------------------------------------------------------------
    |
    | Used to cryptographically sign the JWT using HMAC algorithms (HS256).
    |
    */
    'secret' => env('JWT_SECRET') ?: env('APP_KEY'),

    /*
    |--------------------------------------------------------------------------
    | JWT Encryption Key
    |--------------------------------------------------------------------------
    |
    | Used to encrypt the claims payload using AES-256-GCM. Ensures token claims
    | cannot be viewed or inspected even if intercepted.
    |
    */
    'encryption_key' => env('JWT_ENCRYPTION_KEY') ?: env('APP_KEY'),

    /*
    |--------------------------------------------------------------------------
    | JWT Algorithm
    |--------------------------------------------------------------------------
    |
    | Standard signing algorithm for tokens. Defaults to HS256.
    |
    */
    'algo' => env('JWT_ALGO', 'HS256'),

    /*
    |--------------------------------------------------------------------------
    | Token Time-to-Live (TTL)
    |--------------------------------------------------------------------------
    |
    | Expiration time in minutes for access tokens and refresh tokens.
    | Access token: 60 minutes.
    | Refresh token: 14 days (20,160 minutes).
    |
    */
    'access_ttl' => (int) env('JWT_ACCESS_TTL', 60),
    'refresh_ttl' => (int) env('JWT_REFRESH_TTL', 20160),

    /*
    |--------------------------------------------------------------------------
    | Token Issuer & Audience
    |--------------------------------------------------------------------------
    */
    'issuer' => env('JWT_ISSUER', env('APP_URL', 'http://localhost:8000')),
    'audience' => env('JWT_AUDIENCE', env('APP_URL', 'http://localhost:8000')),

    /*
    |--------------------------------------------------------------------------
    | Cache Blacklist Prefix
    |--------------------------------------------------------------------------
    |
    | Prefix for revoked token IDs in Cache/Redis.
    |
    */
    'blacklist_prefix' => 'jwt_blacklist:',
];
