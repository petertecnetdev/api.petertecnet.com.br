<?php

namespace App\Domain\Events\Http\Controllers;

use App\Domain\Events\Services\EventTicketService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

final class EventTicketController extends Controller
{
    public function __construct(private readonly EventTicketService $tickets) {}

    public function index(Request $request, int $eventId)
    {
        return response()->json(
            $this->tickets->listForEvent($request->user(), $eventId)
        );
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'event_id' => 'nullable|required_without:event_ids|integer|exists:events,id',
            'event_ids' => 'nullable|required_without:event_id|array|min:1|max:100',
            'event_ids.*' => 'required|integer|distinct|exists:events,id',
            'source_ticket_id' => 'nullable|integer|exists:tickets,id',
            'name' => 'nullable|required_without:source_ticket_id|string|max:255',
            'quantity' => 'nullable|required_without:source_ticket_id|integer|min:1|max:100000',
            'price' => 'nullable|required_without:source_ticket_id|numeric|min:0|max:999999.99',
            'ticket_type' => 'nullable|in:courtesy,standard,vip,premium,student,half,full',
            'limit_date' => 'nullable|date',
            'description' => 'nullable|string|max:5000',
        ]);

        $result = $this->tickets->createForEvents($request->user(), $data);

        return response()->json($result['payload'], $result['status']);
    }

    public function update(Request $request, int $ticketId)
    {
        $data = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'quantity' => 'sometimes|required|integer|min:1|max:100000',
            'price' => 'sometimes|required|numeric|min:0|max:999999.99',
            'ticket_type' => 'sometimes|nullable|in:courtesy,standard,vip,premium,student,half,full',
            'limit_date' => 'sometimes|nullable|date',
            'description' => 'sometimes|nullable|string|max:5000',
        ]);

        return response()->json([
            'message' => 'Ingresso atualizado.',
            'ticket' => $this->tickets->updateTicket($request->user(), $ticketId, $data),
        ]);
    }

    public function destroy(Request $request, int $ticketId)
    {
        $this->tickets->deleteTicket($request->user(), $ticketId);

        return response()->json(['message' => 'Ingresso removido.']);
    }
}
