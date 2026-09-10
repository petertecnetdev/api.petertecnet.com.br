<?php

namespace App\Domain\Commerce\Http\Controllers;

use App\Domain\Commerce\Services\TicketAvailabilityQueryService;
use App\Http\Controllers\Controller;
use App\Support\ApplicationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class TicketAvailabilityController extends Controller
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly TicketAvailabilityQueryService $availability,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'event_ids' => 'required|array|min:1|max:50',
            'event_ids.*' => 'required|integer|min:1',
        ]);

        return response()->json([
            'data' => $this->availability->forEvents($this->context->id(), $data['event_ids']),
        ]);
    }
}
