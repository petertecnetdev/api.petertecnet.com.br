<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Establishment;
use App\Models\Event;
use App\Models\Ticket;
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

    public function storeTicket(Request $request, Establishment $establishment, Event $event): JsonResponse
    {
        $data = $request->validate([
            'app_id' => ['nullable', 'integer', 'exists:applications,id'],
            'name' => ['required', 'string', 'max:255'],
            'ticket_type' => ['required', 'string', 'max:255'],
            'type' => ['nullable', 'string', 'max:255'],
            'price' => ['required', 'numeric', 'min:0'],
            'quantity' => ['required', 'integer', 'min:0'],
            'limit_date' => ['nullable', 'date'],
            'description' => ['nullable', 'string', 'max:5000'],
        ]);

        return response()->json(
            $this->events->createTicket(
                $establishment,
                $event,
                $data,
                isset($data['app_id']) ? (int) $data['app_id'] : null
            ),
            201
        );
    }

    public function updateTicket(Request $request, Establishment $establishment, Event $event, Ticket $ticket): JsonResponse
    {
        $data = $request->validate([
            'app_id' => ['nullable', 'integer', 'exists:applications,id'],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'ticket_type' => ['sometimes', 'required', 'string', 'max:255'],
            'type' => ['sometimes', 'nullable', 'string', 'max:255'],
            'price' => ['sometimes', 'required', 'numeric', 'min:0'],
            'quantity' => ['sometimes', 'required', 'integer', 'min:0'],
            'limit_date' => ['sometimes', 'nullable', 'date'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ]);

        return response()->json(
            $this->events->updateTicket(
                $establishment,
                $event,
                $ticket,
                $data,
                isset($data['app_id']) ? (int) $data['app_id'] : null
            )
        );
    }
}
