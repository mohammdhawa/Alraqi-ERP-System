<?php

declare(strict_types=1);

/**
 * Sanctum Configuration
 *
 * These values override Sanctum's defaults for ERP requirements.
 *
 * IMPORTANT: In your .env file, set:
 *   SANCTUM_TOKEN_EXPIRATION=15
 *
 * WHY 15 minutes for access tokens:
 * - Short enough to limit damage from token theft.
 * - Long enough that legitimate users rarely experience mid-workflow expiration.
 * - The refresh flow handles renewal transparently.
 *
 * WHY stateful domains:
 * - If using Sanctum's cookie-based SPA authentication (optional),
 *   list your frontend domains here.
 * - For pure token-based auth (mobile, cross-domain SPA), this isn't needed.
 *   But it's set up for when you want SPA cookie auth as an alternative.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Stateful Domains
    |--------------------------------------------------------------------------
    |
    | Add your SPA domains here if using cookie-based SPA authentication.
    | For token-only authentication, this can remain as defaults.
    |
    */
    'stateful' => explode(',', env(
        'SANCTUM_STATEFUL_DOMAINS',
        sprintf(
            '%s%s',
            'localhost,localhost:3000,localhost:5173,127.0.0.1,127.0.0.1:8000,::1',
            env('APP_URL') ? ',' . parse_url(env('APP_URL'), PHP_URL_HOST) : ''
        )
    )),

    /*
    |--------------------------------------------------------------------------
    | Expiration Minutes
    |--------------------------------------------------------------------------
    |
    | Access tokens expire after this many minutes. Set to 15 for security.
    | The client should use the /auth/refresh endpoint before expiration.
    |
    */
    'expiration' => env('SANCTUM_TOKEN_EXPIRATION', 15),

];