<?php

namespace App\Domain\Analytics\Http\Controllers;

use App\Domain\Analytics\Services\RevenueFunnelService;
use App\Http\Controllers\Controller;
use App\Support\ApplicationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class RevenueFunnelController extends Controller
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly RevenueFunnelService $revenueFunnel,
    ) {}

    public function show(Request $request, int $organizationId): JsonResponse
    {
        return response()->json($this->revenueFunnel->metrics(
            $this->context->id(),
            $organizationId,
            $request->user(),
            (int) $request->query('days', 30),
        ));
    }
}
