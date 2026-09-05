<?php

namespace App\Domain\Events\Http\Controllers;

use App\Domain\Events\Services\EventAttendanceMetricsService;
use App\Http\Controllers\Controller;
use App\Support\ApplicationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class EventAttendanceMetricsController extends Controller
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly EventAttendanceMetricsService $metrics,
    ) {}

    public function show(Request $request, int $eventId): JsonResponse
    {
        return response()->json(
            $this->metrics->forManagedEvent(
                $this->context->id(),
                $eventId,
                $request->user(),
            )
        );
    }
}
