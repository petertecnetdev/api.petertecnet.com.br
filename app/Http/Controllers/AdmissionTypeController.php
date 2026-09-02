<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\Ticket;
use App\Support\ApplicationContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AdmissionTypeController extends Controller
{
    public function __construct(private readonly ApplicationContext $context) {}

    public function store(Request $request)
    {
        $this->context->requireCapability('events');
        $data = $request->validate([
            'event_id' => 'required|integer|exists:events,id',
            'name' => 'required|string|max:255',
            'quantity' => 'required|integer|min:1|max:100000',
            'price' => 'required|numeric|min:0|max:999999.99',
            'ticket_type' => 'nullable|in:courtesy,standard,vip,premium,student,half,full',
            'limit_date' => 'nullable|date',
            'description' => 'nullable|string|max:5000',
        ]);

        $event = Event::query()
            ->where('app_id', $this->context->id())
            ->where('app_slug', $this->context->slug())
            ->with('production')
            ->findOrFail((int) $data['event_id']);

        abort_unless($event->production, 404, 'Organização responsável pelo evento não encontrada.');
        abort_unless(
            Auth::user()?->hasProfile('Administrador') || (int) $event->production->user_id === (int) Auth::id(),
            403,
            'Você não pode gerenciar credenciais de acesso deste evento.'
        );
        abort_if($event->is_cancelled, 422, 'Não é possível criar credenciais para um evento cancelado.');

        $price = round((float) $data['price'], 2);
        $isPaid = $price > 0;
        $ticketType = $data['ticket_type'] ?? ($isPaid ? 'standard' : 'courtesy');

        $ticket = Ticket::create([
            'app_id' => $this->context->id(),
            'app_slug' => $this->context->slug(),
            'event_id' => $event->id,
            'name' => trim($data['name']),
            'ticket_type' => $isPaid ? $ticketType : 'courtesy',
            'type' => $isPaid ? 'paid' : 'courtesy',
            'price' => $price,
            'quantity' => (int) $data['quantity'],
            'limit_date' => $data['limit_date'] ?? null,
            'description' => $data['description'] ?? null,
        ]);

        return response()->json([
            'message' => $isPaid ? 'Credencial paga criada com sucesso.' : 'Cortesia criada com sucesso.',
            'ticket' => $ticket->loadCount('passes'),
            'admission_type' => $ticket,
        ], 201);
    }
}
