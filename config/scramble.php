<?php

use Dedoc\Scramble\SecurityDocumentation\MiddlewareAuthSecurityStrategy;

/*
 * Only the keys that differ from Scramble's defaults — the package merges the
 * rest in. Scramble is a dev dependency and production installs --no-dev, so
 * nothing here may touch one of its classes at load time: `::class` is a plain
 * string, but an enum case such as OpenApiVersion::V3_1 would fatal the moment
 * the config is read (or cached) on the host.
 */
return [
    'info' => [
        'version' => env('API_VERSION', '1.0.0'),
        'description' => 'Abu Saleem Transactions API. Every endpoint outside the auth, ERP, dev and '
            .'maintenance-bootstrap routes needs a Sanctum bearer token from `POST /api/auth/login`. '
            .'A token alone is not enough: each route also checks the caller\'s screen grant '
            .'(`screen.permission:<screen>,<action>`), and a 403 means the role lacks it.',
    ],

    'ui' => [
        'title' => 'Abu Saleem Transactions API',
    ],

    // Marks routes behind auth:sanctum as bearer-secured and everything else public.
    'security_strategy' => MiddlewareAuthSecurityStrategy::class,
];
