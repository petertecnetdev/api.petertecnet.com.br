<?php

namespace App\Domain\Events\Http\Controllers;

use App\Domain\Events\Services\EventLifecycleService;
use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\User;
use App\Support\ApplicationContext;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

final class EventLifecycleController extends Controller
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly EventLifecycleService $lifecycle,
    ) {}

    public function show(Request $request, int $id)
    {
        $event = $this->ownedEvent($id, $request->user());
        return response()->json([
            'event' => $event,
            'impact' => $this->lifecycle->impact($event),
            'history' => $this->lifecycle->history($event),
        ]);
    }

    public function cancel(Request $request, int $id)
    {
        $data = $request->validate(['reason' => 'required|string|min:5|max:4000']);
        $event = $this->ownedEvent($id, $request->user());
        $result = $this->lifecycle->cancel($event, $request->user(), trim($data['reason']));

        return response()->json([
            'message' => $result['impact']['paid_orders_count'] > 0
                ? 'Evento cancelado. Novas vendas foram bloqueadas e os reembolsos dos pedidos pagos foram processados ou registrados para acompanhamento.'
                : 'Evento cancelado. Novas vendas foram bloqueadas.',
            ...$result,
        ]);
    }

    public function postpone(Request $request, int $id)
    {
        $data = $request->validate([
            'reason' => 'required|string|min:5|max:4000',
            'refund_deadline_at' => 'nullable|date|after:now',
        ]);
        $event = $this->ownedEvent($id, $request->user());
        $deadline = ! empty($data['refund_deadline_at'])
            ? Carbon::parse($data['refund_deadline_at'], config('app.timezone'))
            : null;
        $updated = $this->lifecycle->postpone($event, $request->user(), trim($data['reason']), $deadline);

        return response()->json([
            'message' => 'Evento adiado. As vendas estão pausadas e os participantes foram avisados.',
            'event' => $updated,
            'impact' => $this->lifecycle->impact($updated),
        ]);
    }

    public function reschedule(Request $request, int $id)
    {
        $data = $request->validate([
            'start_date' => 'required|date',
            'end_date' => 'required|date|after:start_date',
            'reason' => 'required|string|min:5|max:4000',
            'refund_deadline_at' => 'nullable|date|after:now',
        ]);
        $event = $this->ownedEvent($id, $request->user());
        $start = Carbon::parse($data['start_date'], config('app.timezone'));
        $end = Carbon::parse($data['end_date'], config('app.timezone'));
        $deadline = ! empty($data['refund_deadline_at'])
            ? Carbon::parse($data['refund_deadline_at'], config('app.timezone'))
            : null;
        $updated = $this->lifecycle->reschedule($event, $request->user(), $start, $end, trim($data['reason']), $deadline);

        return response()->json([
            'message' => 'Evento reagendado. Os ingressos continuam válidos e os participantes foram avisados da nova data.',
            'event' => $updated,
            'impact' => $this->lifecycle->impact($updated),
        ]);
    }

    private function ownedEvent(int $id, User $user): Event
    {
        $event = Event::query()->where('app_id', $this->context->id())->with('production')->findOrFail($id);
        abort_unless($event->production && (int) $event->production->app_id === $this->context->id(), 404, 'Evento não encontrado neste contexto.');
        abort_unless($user->hasProfile('Administrador') || (int) $event->production->user_id === (int) $user->id, 403, 'Você não pode gerenciar este evento.');
        return $event;
    }
}
