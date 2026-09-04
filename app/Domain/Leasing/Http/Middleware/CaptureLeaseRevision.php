<?php

namespace App\Domain\Leasing\Http\Middleware;

use App\Domain\Leasing\Services\LeaseRevisionService;
use App\Support\ApplicationContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class CaptureLeaseRevision
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly LeaseRevisionService $revisions,
    ) {}

    public function handle(Request $request, Closure $next, string $eventType = 'updated'): Response
    {
        $leaseId = (int) ($request->route('leaseId') ?? 0);
        $before = $leaseId > 0 ? $this->revisions->snapshot($this->context->id(), $leaseId) : null;
        $response = $next($request);

        if ($leaseId > 0 && $response->getStatusCode() < 400) {
            $this->revisions->record(
                $this->context->id(),
                $leaseId,
                $request->user()?->id ? (int) $request->user()->id : null,
                $eventType,
                $before,
            );
        }

        return $response;
    }
}
