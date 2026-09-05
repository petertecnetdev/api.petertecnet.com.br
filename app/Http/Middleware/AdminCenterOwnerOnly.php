<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class AdminCenterOwnerOnly
{
    public const OWNER_EMAIL = 'petertecnet@gmail.com';

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $email = Str::lower(trim((string) ($user?->email ?? '')));

        if (! $user || ! hash_equals(self::OWNER_EMAIL, $email)) {
            return response()->json([
                'message' => 'Acesso restrito ao proprietário da Admin Center Peter Tecnet.',
            ], 403);
        }

        return $next($request);
    }
}
