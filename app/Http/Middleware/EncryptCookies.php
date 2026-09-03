<?php

namespace App\Http\Middleware;

use Illuminate\Cookie\Middleware\EncryptCookies as Middleware;

class EncryptCookies extends Middleware
{
    /**
     * Global Identity secrets are already high-entropy opaque values. Only
     * SHA-256 hashes are persisted server-side, so keeping the raw browser
     * values stable makes rotation and cache lookup deterministic.
     *
     * @var array<int, string>
     */
    protected $except = [
        'peter_ecosystem_session',
        'peter_ecosystem_refresh',
    ];
}
