<?php

namespace App\Domain\Leasing\Http\Middleware;

use App\Support\ApplicationContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

final class PreventLeaseOverlap
{
    public function __construct(private readonly ApplicationContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $leaseId = $request->route('leaseId') ? (int) $request->route('leaseId') : null;
        $existing = $leaseId
            ? DB::table('leases')->where('app_id', $this->context->id())->where('id', $leaseId)->whereNull('deleted_at')->first()
            : null;

        $propertyId = (int) ($request->input('property_id') ?: ($existing->property_id ?? 0));
        $startsOn = $request->input('starts_on') ?: ($existing->starts_on ?? null);
        $endsOn = $request->input('ends_on') ?: ($existing->ends_on ?? null);

        if ($propertyId > 0 && $startsOn && $endsOn) {
            $overlap = DB::table('leases')
                ->where('app_id', $this->context->id())
                ->where('property_id', $propertyId)
                ->whereNull('deleted_at')
                ->whereNotIn('status', ['ended', 'cancelled'])
                ->when($leaseId, fn ($query) => $query->where('id', '!=', $leaseId))
                ->whereDate('starts_on', '<=', $endsOn)
                ->whereDate('ends_on', '>=', $startsOn)
                ->exists();

            if ($overlap) {
                return response()->json([
                    'message' => 'Já existe uma locação com período sobreposto para este imóvel. Encerre, cancele ou ajuste o período do outro contrato.',
                    'code' => 'LEASE_PERIOD_OVERLAP',
                ], 422);
            }
        }

        return $next($request);
    }
}
