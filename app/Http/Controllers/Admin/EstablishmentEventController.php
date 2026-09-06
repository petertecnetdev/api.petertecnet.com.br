<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Establishment;
use App\Models\Event;
use App\Services\Admin\EstablishmentEventService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EstablishmentEventController extends Controller
{
    public function __construct(private readonly EstablishmentEventService $events)
    {
    }

    public function index(Request $request, Establishment $establishment): JsonResponse
    {
        $data = $request->validate([
            'app_id' => ['nullable', 'integer', 'exists:applications,id'],
        ]);

        return response()->json(
            $this->events->listing(
                $establishment,
                isset($data['app_id']) ? (int) $data['app_id'] : null
            )
        );
    }

    public function tickets(Request $request, Establishment $establishment, Event $event): JsonResponse
    {
        $data = $request->validate([
            'app_id' => ['nullable', 'integer', 'exists:applications,id'],
        ]);

        return response()->json(
            $this->events->ticketDetails(
                $establishment,
                $event,
                isset($data['app_id']) ? (int) $data['app_id'] : null
            )
        );
    }
}
