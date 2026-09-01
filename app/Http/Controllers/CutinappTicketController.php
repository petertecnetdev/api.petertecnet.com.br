<?php

namespace App\Http\Controllers;

use App\Models\Application;
use App\Models\Event;
use App\Models\Ticket;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CutinappTicketController extends Controller
{
    private const APP = 'cutinapp';

    public function store(Request $request)
    {
        $data = $request->validate([
            'event_id' => 'required|integer|exists:events,id',
            'name' => 'required|string|max:255',
            'quantity' => 'required|integer|min:1|max:100000',
            'price' => 'required|numeric|min:0|max:999999.99',
            'ticket_type' => 'nullable|in:courtesy,standard,vip,premium,student,half,full',
            'limit_date' => 'nullable|date',
            'description' => 'nullable|string|max:5000',
        ], [
            'required' => 'Preencha :attribute.',
            'integer' => ':attribute precisa ser um número inteiro.',
            'numeric' => ':attribute precisa ser um valor numérico.',
            'exists' => ':attribute não foi encontrado ou não está mais disponível.',
            'date' => 'Informe uma data válida em :attribute.',
            'min' => ':attribute está abaixo do mínimo permitido.',
            'max' => ':attribute ultrapassou o limite permitido.',
            'in' => 'Selecione um tipo de ingresso válido.',
        ], [
            'event_id' => 'o evento',
            'name' => 'o nome do ingresso',
            'quantity' => 'a quantidade',
            'price' => 'o preço',
            'ticket_type' => 'o tipo do ingresso',
            'limit_date' => 'a data limite',
            'description' => 'a descrição',
        ]);

        $appId = $this->applicationId();
        $event = Event::query()
            ->where('app_id', $appId)
            ->where('app_slug', self::APP)
            ->with('production')
            ->findOrFail((int) $data['event_id']);

        abort_unless($event->production, 404, 'Produção do evento não encontrada.');
        abort_unless(
            Auth::user()?->hasProfile('Administrador') || (int) $event->production->user_id === (int) Auth::id(),
            403,
            'Você não pode gerenciar ingressos deste evento.'
        );
        abort_if($event->is_cancelled, 422, 'Não é possível criar ingressos para um evento cancelado.');

        $price = round((float) $data['price'], 2);
        $isPaid = $price > 0;
        $ticketType = $data['ticket_type'] ?? ($isPaid ? 'standard' : 'courtesy');

        if ($isPaid && $price < 0.01) {
            abort(422, 'O preço mínimo de um ingresso pago é R$ 0,01.');
        }

        $ticket = Ticket::create([
            'app_id' => $appId,
            'app_slug' => self::APP,
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
            'message' => $isPaid ? 'Ingresso pago criado com sucesso.' : 'Cortesia criada com sucesso.',
            'ticket' => $ticket->loadCount('passes'),
        ], 201);
    }

    private function applicationId(): int
    {
        $application = Application::query()
            ->where('slug', self::APP)
            ->where('is_active', true)
            ->first();

        abort_unless($application, 503, 'A Cutinapp não está registrada corretamente na API.');

        return (int) $application->id;
    }
}
