<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class RequireAdminPermission
{
    public function handle(Request $request, Closure $next, string ...$permissions)
    {
        $user = $request->user();
        abort_unless($user, 401, 'Autenticação administrativa necessária.');

        if ($user->hasProfile('Administrador') || $user->hasPermission('ecosystem_manage')) {
            return $next($request);
        }

        foreach ($permissions as $permission) {
            if ($user->hasPermission($permission)) return $next($request);
        }

        abort(403, 'Usuário sem permissão para esta operação administrativa.');
    }
}
