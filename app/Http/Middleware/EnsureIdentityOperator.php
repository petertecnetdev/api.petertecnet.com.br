<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureIdentityOperator
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('api');
        $permissions = is_array($user?->profile?->permissions) ? $user->profile->permissions : [];

        if (! $user || ! array_intersect(['ecosystem_manage', 'security_view', 'operations_view'], $permissions)) {
            return response()->json([
                'success' => false,
                'message' => 'Você não possui permissão para administrar o Peter Identity.',
                'code' => 'IDENTITY_OPERATOR_REQUIRED',
            ], 403);
        }

        return $next($request);
    }
}
