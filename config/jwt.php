<?php
// config/jwt.php
return [

    'secret' => env('JWT_SECRET'),

    'keys' => [
        'public'    => env('JWT_PUBLIC_KEY'),
        'private'   => env('JWT_PRIVATE_KEY'),
        'passphrase'=> env('JWT_PASSPHRASE'),
    ],

    // Legacy/default JWT policy remains configurable. Peter Identity overrides its own
    // access-token TTL per request while older consumers migrate safely.
    'ttl'         => env('JWT_TTL', 10080),
    'refresh_ttl' => env('JWT_REFRESH_TTL', 40320),

    'algo'          => env('JWT_ALGO', Tymon\JWTAuth\Providers\JWT\Provider::ALGO_HS256),

    'required_claims'    => [
        'iss', 'iat', 'exp', 'nbf', 'sub', 'jti',
    ],

    // Preserve the generic Identity context when tymon/jwt-auth refreshes a token.
    'persistent_claims'  => [
        'sid', 'app', 'amr', 'ver',
    ],

    'lock_subject'       => true,

    'leeway'             => env('JWT_LEEWAY', 0),

    'blacklist_enabled'  => env('JWT_BLACKLIST_ENABLED', true),

    'blacklist_grace_period' => env('JWT_BLACKLIST_GRACE_PERIOD', 0),

    'decrypt_cookies'    => false,

    'providers' => [
        'jwt'       => Tymon\JWTAuth\Providers\JWT\Lcobucci::class,
        'auth'      => Tymon\JWTAuth\Providers\Auth\Illuminate::class,
        'storage'   => Tymon\JWTAuth\Providers\Storage\Illuminate::class,
    ],

];
