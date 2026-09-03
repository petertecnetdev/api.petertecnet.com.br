<?php

namespace App\Http\Middleware;

use Illuminate\Cookie\Middleware\EncryptCookies as Middleware;

class EncryptCookies extends Middleware
{
    /**
     * The names of the cookies that should not be encrypted.
     *
     * The ecosystem SSO cookie is already an opaque high-entropy random token.
     * Its raw value is never persisted server-side: only a SHA-256 cache key is
     * stored, so Laravel cookie encryption would add no authorization property
     * while making the browser/API contract harder to test and rotate safely.
     *
     * @var array<int, string>
     */
    protected $except = [
        'peter_ecosystem_session',
    ];
}
