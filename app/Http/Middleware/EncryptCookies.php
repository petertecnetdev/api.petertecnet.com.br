<?php

namespace App\Http\Middleware;

use Illuminate\Cookie\Middleware\EncryptCookies as Middleware;

class EncryptCookies extends Middleware
{
    /**
     * Identity cookies carry only high-entropy opaque secrets. The API persists
     * SHA-256 hashes, never the raw values. They must remain byte-for-byte stable
     * so rotation, replay detection and Redis/database lookup are deterministic.
     *
     * @var array<int, string>
     */
    protected $except = [
        'peter_ecosystem_session',
        'peter_ecosystem_refresh',
    ];
}
