<?php

namespace App\Http\Middleware;

use App\Domain\Finance\Services\EntitlementLimitService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnforceRevenueEntitlements
{
    public function __construct(private readonly EntitlementLimitService $limits) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->routeIs('establishment.store')) {
            return $next($request);
        }

        $user = $request->user('api');
        $appId = (int) $request->input('app_id', 0);

        if (! $user || $appId <= 0) {
            return $next($request);
        }

        $decision = $this->limits->check($appId, (int) $user->id, 'establishments.max');

        if ($decision['allowed']) {
            return $next($request);
        }

        return response()->json([
            'success' => false,
            'error' => 'upgrade_required',
            'message' => 'Seu plano atingiu o limite de estabelecimentos. Faça upgrade para adicionar outra unidade.',
            'upgrade' => [
                'entitlement' => $decision['key'],
                'current' => $decision['current'],
                'limit' => $decision['limit'],
                'plan_code' => $decision['plan_code'],
            ],
        ], 402);
    }
}
