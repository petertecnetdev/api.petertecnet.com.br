<?php

namespace App\Domain\Leasing\Http\Middleware;

use App\Support\ApplicationContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

final class ProtectPropertyLeaseState
{
    public function __construct(private readonly ApplicationContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->has('status')) return $next($request);

        $propertyId = (int) $request->route('propertyId');
        $requested = (string) $request->input('status');

        $hasCurrentLease = DB::table('leases')
            ->where('app_id', $this->context->id())
            ->where('property_id', $propertyId)
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->whereDate('starts_on', '<=', today())
            ->whereDate('ends_on', '>=', today())
            ->exists();

        if ($hasCurrentLease && $requested !== 'occupied') {
            return response()->json([
                'message' => 'O status do imóvel é controlado pela locação vigente. Encerre o contrato antes de marcar o imóvel como disponível, em manutenção ou inativo.',
                'code' => 'PROPERTY_STATUS_DERIVED_FROM_LEASE',
            ], 422);
        }

        if (! $hasCurrentLease && $requested === 'occupied') {
            return response()->json([
                'message' => 'Um imóvel só pode ficar ocupado quando existir uma locação vigente.',
                'code' => 'PROPERTY_OCCUPIED_REQUIRES_LEASE',
            ], 422);
        }

        return $next($request);
    }
}
