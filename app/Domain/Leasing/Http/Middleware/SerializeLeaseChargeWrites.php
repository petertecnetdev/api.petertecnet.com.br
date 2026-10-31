<?php

namespace App\Domain\Leasing\Http\Middleware;

use App\Support\ApplicationContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

final class SerializeLeaseChargeWrites
{
    public function __construct(private readonly ApplicationContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $leaseId = filter_var($request->route('leaseId'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);

        abort_unless($leaseId !== false, 404);

        return DB::transaction(function () use ($request, $next, $leaseId): Response {
            DB::table('leases')
                ->where('app_id', $this->context->id())
                ->where('id', $leaseId)
                ->whereNull('deleted_at')
                ->lockForUpdate()
                ->firstOrFail();

            return $next($request);
        }, 3);
    }
}
