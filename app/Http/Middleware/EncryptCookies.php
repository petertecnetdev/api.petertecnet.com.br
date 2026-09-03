<?php

namespace App\Http\Middleware;

use Illuminate\Cookie\Middleware\EncryptCookies as Middleware;

class EncryptCookies extends Middleware
{
    /**
     * Opaque Identity secrets are already high-entropy and only their SHA-256 hashes
     * are persisted. They must stay byte-stable between browser and API.
     *
     * @var array<int, string>
     */
    protected $except = [
        'peter_ecosystem_session',
        'peter_ecosystem_refresh',
    ];
}
