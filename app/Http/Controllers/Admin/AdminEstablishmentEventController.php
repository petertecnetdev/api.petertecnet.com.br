<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Events\Services\AdminEstablishmentEventService;
use App\Http\Controllers\Controller;
use App\Models\Establishment;
use App\Models\Event;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AdminEstablishmentEventController extends Controller
{
    public function __construct(private readonly AdminEstablishmentEventService $events) {}

    public function index(Request $request, Establishment $establishment): JsonResponse
    {
        $this->authorizeAccess($request);
        $data = $request->validate([
            'app_id' => ['required', 'integer', 'exists:applications,id'],
        ]);

        return response()->json(
            $this->events->index($establishment, (int) $data['app_id'])
        );
    }

    public function destroyMany(Request $request, Establishment $establishment): JsonResponse
    {
        $this->authorizeAccess($request);
        $data = $request->validate([
            'app_id' => ['required', 'integer', 'exists:applications,id'],
            'event_ids' => ['required', 'array', 'min:1', 'max:100'],
            'event_ids.*' => ['required', 'integer', 'distinct', 'min:1'],
        ], [
            'event_ids.required' => 'Selecione pelo menos um evento para excluir.',
            'event_ids.min' => 'Selecione pelo menos um evento para excluir.',
            'event_ids.max' => 'Exclua no máximo 100 eventos por operação.',
            'event_ids.*.distinct' => 'A seleção contém eventos duplicados.',
        ]);

        return response()->json(
            $this->events->deleteMany(
                $establishment,
                (int) $data['app_id'],
                $data['event_ids'],
                $request->user()?->id,
                $request->ip(),
                $request->userAgent(),
            )
        );
    }

    public function duplicate(Request $request, Establishment $establishment, Event $event): JsonResponse
    {
        $this->authorizeAccess($request);
        $data = $request->validate([
            'app_id' => ['required', 'integer', 'exists:applications,id'],
            'date' => ['required', 'date_format:Y-m-d'],
        ], [
            'date.required' => 'Informe a nova data do evento.',
            'date.date_format' => 'Informe uma data válida.',
        ]);

        return response()->json(
            $this->events->duplicate(
                $establishment,
                $event,
                (int) $data['app_id'],
                $data['date'],
                $request->user()?->id,
                $request->ip(),
                $request->userAgent(),
            ),
            201,
        );
    }

    private function authorizeAccess(Request $request): void
    {
        $email = strtolower(trim((string) $request->user()?->email));
        abort_unless($email === 'petertecnet@gmail.com', 403, 'Apenas o administrador principal pode gerenciar eventos por este painel.');
    }
}
