<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureCentralAdmin
{
    public function handle(Request $request, Closure $next)
    {
        $user = auth('api')->user();

        if (! $user) {
            return response()->json(['error' => 'Não autenticado.'], 401);
        }

        $profile = strtolower((string) optional($user->profile)->name);
        $allowedProfile = in_array($profile, ['admin', 'administrator', 'administrador', 'superadmin', 'super_admin'], true);
        $allowedPermission = $user->hasPermission('admin_panel') || $user->hasPermission('system_manage');

        if (! $allowedProfile && ! $allowedPermission) {
            return response()->json(['error' => 'Acesso restrito à administração central da Peter Tecnet.'], 403);
        }

        return $next($request);
    }
}
